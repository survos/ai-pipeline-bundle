<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Command;

use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment as TwigEnvironment;

#[AsCommand('ai:pipeline:tasks', 'List all registered AI pipeline tasks')]
final class AiPipelineTasksCommand extends Command
{
    /**
     * Rough, intentionally conservative OCR cost estimates for 1,000 512px images.
     * These are guidance numbers for comparing modes, not billing truth.
     *
     * @var array<string, array{method: string, provider: string, est: string, quality: string, speed: string}>
     */
    private const OCR_PROFILES = [
        'ocr' => [
            'method'   => 'Vision OCR (general model)',
            'provider' => 'OpenAI gpt-4o',
            'est'      => '~$4.00 / 1k',
            'quality'  => 'Good text, weak layout',
            'speed'    => 'Medium',
        ],
        'ocr_mistral' => [
            'method'   => 'Layout-aware OCR API',
            'provider' => 'Mistral OCR',
            'est'      => '~$1.50 / 1k',
            'quality'  => 'Strong text + layout',
            'speed'    => 'Fast',
        ],
        // Expected future task (currently missing in registry)
        'ocr_tesseract_remote' => [
            'method'   => 'Remote Tesseract service',
            'provider' => 'ai-tools.survos.com',
            'est'      => '~$0.20 / 1k (infra only)',
            'quality'  => 'Cheapest, weaker on hard docs',
            'speed'    => 'Fast/CPU-bound',
        ],
    ];

    public function __construct(
        private readonly AiTaskRegistry $registry,
        private readonly TwigEnvironment $twig,
        #[AutowireIterator('ai_pipeline.task')]
        iterable $tasks,
    ) {
        foreach ($tasks as $task) {
            if ($task instanceof AiTaskInterface) {
                $this->tasksByName[$task->getTask()] = $task;
            }
        }
        parent::__construct();
    }

    /** @var array<string, AiTaskInterface> */
    private array $tasksByName = [];

    public function __invoke(SymfonyStyle $io): int
    {
        $taskMap = $this->registry->getTaskMap();   // compiled — no service resolution

        $io->title('AI Pipeline Task Registry  (compiled at container build time)');

        if ($taskMap === []) {
            $io->warning('No tasks registered. Implement AiTaskInterface and register as a service.');
            return Command::SUCCESS;
        }

        $verbose = $io->isVerbose();      // -v
        $veryVerbose = $io->isVeryVerbose(); // -vv

        $rows = [];
        foreach ($taskMap as $taskName => $serviceId) {
            $task = $this->resolveTask($taskName);
            $meta = $task?->getMeta() ?? [];

            $class = $task
                ? (new \ReflectionClass($task::class))->getShortName()
                : basename(str_replace('\\', '/', $serviceId));

            $row = [$taskName, $class, $serviceId];

            if ($verbose) {
                $profile = self::OCR_PROFILES[$taskName] ?? null;
                $row[] = (string)($meta['agent'] ?? '');
                $row[] = (string)($meta['platform'] ?? '');
                $row[] = (string)($meta['model'] ?? '');
                $row[] = $profile['est'] ?? '';
            }

            $rows[] = $row;
        }

        $headers = ['Task', 'Handler class', 'Service ID'];
        if ($verbose) {
            $headers[] = 'Agent';
            $headers[] = 'Platform';
            $headers[] = 'Model';
            $headers[] = 'Est cost / 1k';
        }

        $io->table($headers, $rows);

        // OCR-focused view: present + missing expected variants
        $io->section('OCR Modes');
        $ocrRows = [];
        foreach (self::OCR_PROFILES as $taskName => $profile) {
            $ocrRows[] = [
                $taskName,
                isset($taskMap[$taskName]) ? 'yes' : 'no',
                $profile['provider'],
                $profile['method'],
                $profile['quality'],
                $profile['speed'],
                $profile['est'],
            ];
        }
        $io->table(['Task', 'Registered', 'Provider', 'Method', 'Quality', 'Speed', 'Est / 1k 512px'], $ocrRows);

        if (!isset($taskMap['ocr_tesseract_remote'])) {
            $io->warning('Missing OCR task: ocr_tesseract_remote (ai-tools.survos.com).');
        }

        if ($veryVerbose) {
            $io->section('Prompt Details (-vv)');
            foreach ($taskMap as $taskName => $serviceId) {
                $task = $this->resolveTask($taskName);
                $meta = $task?->getMeta() ?? [];

                $io->writeln(sprintf('<info>%s</info> (%s)', $taskName, $serviceId));

                $systemTpl = "@SurvosAiPipeline/prompt/{$taskName}/system.html.twig";
                $userTpl = "@SurvosAiPipeline/prompt/{$taskName}/user.html.twig";

                $systemSrc = $this->readTemplate($systemTpl);
                $userSrc = $this->readTemplate($userTpl);

                if ($systemSrc !== null) {
                    $io->writeln("  system template: {$systemTpl}");
                    $io->writeln('  ---');
                    $io->writeln($this->indent($systemSrc, '  '));
                } elseif (isset($meta['system_prompt'])) {
                    $io->writeln('  system prompt (inline meta):');
                    $io->writeln($this->indent((string)$meta['system_prompt'], '  '));
                }

                if ($userSrc !== null) {
                    $io->writeln("  user template: {$userTpl}");
                    $io->writeln('  ---');
                    $io->writeln($this->indent($userSrc, '  '));
                }

                if (!empty($meta)) {
                    $io->writeln('  meta: ' . json_encode($meta, JSON_UNESCAPED_SLASHES));
                }

                $io->newLine();
            }
        } elseif ($verbose) {
            $io->note('Use -vv to print resolved system/user prompts for each task.');
        }

        $io->success(sprintf('%d task(s) registered.', count($taskMap)));

        return Command::SUCCESS;
    }

    private function resolveTask(string $taskName): ?AiTaskInterface
    {
        return $this->tasksByName[$taskName] ?? null;
    }

    private function readTemplate(string $template): ?string
    {
        try {
            return trim($this->twig->getLoader()->getSourceContext($template)->getCode());
        } catch (\Throwable) {
            return null;
        }
    }

    private function indent(string $text, string $prefix): string
    {
        return $prefix . str_replace("\n", "\n{$prefix}", trim($text));
    }
}
