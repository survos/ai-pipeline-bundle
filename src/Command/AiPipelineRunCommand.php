<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Command;

use Survos\AiPipelineBundle\Storage\ArrayResultStore;
use Survos\AiPipelineBundle\Storage\JsonFileResultStore;
use Survos\AiPipelineBundle\Task\AiPipelineRunner;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('ai:pipeline:run', 'Run AI pipeline tasks against a subject (URL, text, etc.)')]
final class AiPipelineRunCommand extends Command
{
    public function __construct(
        private readonly AiPipelineRunner $runner,
        private readonly AiTaskRegistry   $registry,
        #[Autowire('%kernel.project_dir%/var/ai-results')]
        private readonly string $defaultStoreDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('subject', InputArgument::OPTIONAL,
                'Primary input — image URL, text, or other subject (omit for interactive prompt)')
            ->addOption('tasks', 't', InputOption::VALUE_REQUIRED,
                'Comma-separated task names, "all", or "pick" to select interactively',
                'all')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED,
                'Result store: memory (default) or json (persists to var/ai-results/)',
                'memory')
            ->addOption('store-dir', null, InputOption::VALUE_REQUIRED,
                'Directory for json store (default: var/ai-results/)')
            ->addOption('loop', 'l', InputOption::VALUE_NONE,
                'Keep looping — prompt for another subject after each run')
            ->addOption('pretty', 'p', InputOption::VALUE_NONE,
                'Pretty-print full JSON results after each task')
            ->addOption('pause', null, InputOption::VALUE_NONE,
                'Pause for confirmation before each task (implies -vv)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $useJson    = $input->getOption('store') === 'json';
        $storeDir   = $input->getOption('store-dir') ?? $this->defaultStoreDir;
        $loop       = (bool) $input->getOption('loop');
        $pretty     = (bool) $input->getOption('pretty');
        $pause      = (bool) $input->getOption('pause');
        $verbose    = $output->isVerbose();     // -v
        $veryVerbose = $output->isVeryVerbose(); // -vv
        $tasksOpt   = (string) $input->getOption('tasks');
        $subjectArg = $input->getArgument('subject');

        $io->title('AI Pipeline Runner');

        do {
            // ── Subject ──────────────────────────────────────────────────────
            $subject = $subjectArg ?? $io->ask('Subject (image URL, text, etc.)');
            if (!$subject) {
                $io->warning('No subject provided — exiting.');
                return Command::SUCCESS;
            }

            // ── Task queue ───────────────────────────────────────────────────
            $queue = $this->resolveQueue($tasksOpt, $io);
            if ($queue === null) {
                return Command::FAILURE;
            }

            // ── Store ────────────────────────────────────────────────────────
            $store = $useJson
                ? new JsonFileResultStore($subject, $storeDir)
                : new ArrayResultStore($subject);

            // Skip tasks already completed (json store may have prior run)
            $prior   = $store->getAllPrior();
            $pending = array_values(array_filter($queue, fn(string $t) => !isset($prior[$t])));
            $skipped = array_diff($queue, $pending);

            if ($skipped !== []) {
                $io->comment(sprintf('Skipping already-completed: %s', implode(', ', $skipped)));
            }

            if ($pending === []) {
                $io->success('All tasks already completed for this subject.');
                $this->printResults($io, $store->getAllPrior(), $pretty);
            } else {
                $io->section(sprintf('Running %d task(s) against: %s', count($pending), $subject));

                // ── Verbose/pause callbacks ──────────────────────────────────
                if ($verbose || $veryVerbose || $pause) {
                    $this->runner->onBeforeTask(function (string $taskName, array $inputs, array $priorResults) use ($io, $veryVerbose, $pause): void {
                        $io->writeln(sprintf('  → <info>%s</info>', $taskName));
                        if ($veryVerbose) {
                            $io->writeln(sprintf(
                                '    inputs:  %s',
                                json_encode(array_map(fn($v) => mb_substr((string) $v, 0, 80), $inputs), JSON_UNESCAPED_SLASHES)
                            ));
                            $io->writeln(sprintf(
                                '    prior:   [%s]',
                                implode(', ', array_keys($priorResults))
                            ));
                        }
                        if ($pause) {
                            $io->ask('Press Enter to run this task (or Ctrl+C to abort)');
                        }
                    });
                }

                $this->runner->onAfterTask(function (string $taskName, array $result, string $status) use ($io, $pretty): void {
                    $statusLabel = match ($status) {
                        'done'       => '<info>done</info>',
                        'skipped'    => '<comment>skipped</comment>',
                        'failed'     => '<error>FAILED</error>',
                        'no_handler' => '<error>no handler</error>',
                        default      => $status,
                    };
                    $io->writeln(sprintf('  %-30s %s', $taskName, $statusLabel));
                    if ($pretty && $status === 'done') {
                        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    } elseif ($status === 'failed') {
                        $io->writeln(sprintf('    <error>%s</error>', $result['error'] ?? '(no message)'));
                    }
                });

                // ── Run ──────────────────────────────────────────────────────
                while ($pending !== []) {
                    $ran = $this->runner->runNext($store, $pending);
                    if ($ran === null) {
                        break;
                    }
                }

                $io->newLine();
                $this->printResults($io, $store->getAllPrior(), $pretty);

                if ($useJson && $store instanceof JsonFileResultStore) {
                    $io->comment('Saved to: ' . $store->getFilePath());
                }
            }

            $subjectArg = null; // next loop iteration will prompt
        } while ($loop && $io->confirm('Run another subject?', true));

        return Command::SUCCESS;
    }

    // ── Queue resolution ──────────────────────────────────────────────────────

    /**
     * @return list<string>|null  null on error
     */
    private function resolveQueue(string $tasksOpt, SymfonyStyle $io): ?array
    {
        $registered = $this->registry->getTaskMap();
        $allNames   = array_keys($registered);

        if ($tasksOpt === 'all') {
            return $allNames;
        }

        if ($tasksOpt === 'pick') {
            return $this->pickInteractively($allNames, $io);
        }

        $names = array_filter(array_map('trim', explode(',', $tasksOpt)));
        $queue = [];

        foreach ($names as $name) {
            if (!isset($registered[$name])) {
                $io->error(sprintf(
                    'Unknown task "%s". Registered: %s',
                    $name,
                    implode(', ', $allNames) ?: '(none)',
                ));
                return null;
            }
            $queue[] = $name;
        }

        return $queue;
    }

    /**
     * Interactive checkbox-style task picker.
     *
     * @param list<string> $allNames
     * @return list<string>
     */
    private function pickInteractively(array $allNames, SymfonyStyle $io): array
    {
        if ($allNames === []) {
            $io->warning('No tasks registered.');
            return [];
        }

        $chosen = $io->choice(
            'Select tasks to run (separate multiple choices with commas)',
            $allNames,
            null,
            true, // multiselect
        );

        return array_values($chosen);
    }

    // ── Result printing ───────────────────────────────────────────────────────

    private function printResults(SymfonyStyle $io, array $results, bool $pretty): void
    {
        if ($results === []) {
            return;
        }

        $io->section('Results summary');
        foreach ($results as $taskName => $result) {
            if (isset($result['skipped']) || isset($result['failed'])) {
                continue;
            }
            $summary = match (true) {
                isset($result['text'])        => mb_substr((string) $result['text'], 0, 120) . '…',
                isset($result['description']) => mb_substr((string) $result['description'], 0, 120) . '…',
                isset($result['title'])       => $result['title'],
                isset($result['type'])        => sprintf('%s (%.0f%%)', $result['type'], ($result['confidence'] ?? 0) * 100),
                isset($result['keywords'])    => implode(', ', array_slice((array) $result['keywords'], 0, 8)),
                isset($result['summary'])     => mb_substr((string) $result['summary'], 0, 120) . '…',
                default                       => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
            $io->writeln(sprintf('  <info>%-25s</info> %s', $taskName, $summary));
        }

        if ($pretty) {
            $io->newLine();
            $io->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}
