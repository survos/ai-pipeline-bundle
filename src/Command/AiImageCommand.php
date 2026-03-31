<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Command;

use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand('ai:image', 'Run a single AI task against an image')]
final class AiImageCommand extends Command
{
    private const DEFAULT_TASK = 'image_analysis';

    /** @var array<string, string> */
    private const TASK_ALIASES = [
        'ocr_handwriting' => 'transcribe_handwriting',
    ];

    public function __construct(
        private readonly AiTaskRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('image', InputArgument::OPTIONAL, 'Image URL or local file path')
            ->addOption('task', 't', InputOption::VALUE_REQUIRED, 'Task name to run (or omit to choose interactively)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Structured output format: json or yaml', 'json')
            ->addOption('json-only', null, InputOption::VALUE_NONE, 'Print structured result only (no OCR text block)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $image = (string) ($input->getArgument('image') ?? '');
        if ($image === '') {
            $image = (string) $io->ask('Image URL or local path');
        }

        if ($image === '') {
            $io->error('An image URL or local file path is required.');
            return Command::FAILURE;
        }

        $taskName = $this->resolveTaskName($input, $io);
        if ($taskName === null) {
            return Command::FAILURE;
        }

        $task = $this->registry->get($taskName);
        if ($task === null) {
            $io->error(sprintf('Unknown task "%s".', $taskName));
            return Command::FAILURE;
        }

        if (!$task->supports(['image_url' => $image], ['image_url' => $image])) {
            $io->error(sprintf('Task "%s" does not support the provided image.', $taskName));
            return Command::FAILURE;
        }

        $io->title(sprintf('AI Image: %s', $taskName));
        $io->comment($image);

        $result = $task->run(['image_url' => $image], [], ['image_url' => $image]);

        if (!$input->getOption('json-only')) {
            $ocrText = $this->extractOcrText($result);
            if ($ocrText !== null && $ocrText !== '') {
                $io->section('OCR Text');
                $io->writeln($ocrText);
            }
        }

        $structured = $result;
        unset($structured['text']);
        unset($structured['extracted_text']);

        $io->section('Structured Result');
        $format = strtolower((string) $input->getOption('format'));
        if ($format === 'yaml') {
            if (!class_exists(Yaml::class)) {
                $io->error('YAML output requested, but symfony/yaml is not installed.');
                return Command::FAILURE;
            }

            $io->writeln(Yaml::dump($structured, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        } else {
            $io->writeln(json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return Command::SUCCESS;
    }

    private function resolveTaskName(InputInterface $input, SymfonyStyle $io): ?string
    {
        $taskName = trim((string) ($input->getOption('task') ?? ''));
        $taskName = self::TASK_ALIASES[$taskName] ?? $taskName;

        if ($taskName !== '') {
            return $taskName;
        }

        if ($this->registry->has(self::DEFAULT_TASK)) {
            return self::DEFAULT_TASK;
        }

        $tasks = array_keys($this->registry->getTaskMap());
        if ($tasks === []) {
            $io->error('No AI tasks are registered.');
            return null;
        }

        $choice = $io->choice('Select a task to run', $tasks, $tasks[0]);
        return self::TASK_ALIASES[$choice] ?? $choice;
    }

    private function extractOcrText(array $result): ?string
    {
        $text = $result['text'] ?? $result['extracted_text'] ?? $result['ocr_text'] ?? null;
        return is_string($text) ? trim($text) : null;
    }
}
