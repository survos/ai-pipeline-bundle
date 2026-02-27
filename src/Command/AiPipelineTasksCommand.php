<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Command;

use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('ai:pipeline:tasks', 'List all registered AI pipeline tasks')]
final class AiPipelineTasksCommand extends Command
{
    public function __construct(
        private readonly AiTaskRegistry $registry,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $taskMap = $this->registry->getTaskMap();   // compiled — no service resolution

        $io->title('AI Pipeline Task Registry  (compiled at container build time)');

        if ($taskMap === []) {
            $io->warning('No tasks registered. Implement AiTaskInterface and register as a service.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($taskMap as $taskName => $serviceId) {
            $rows[] = [
                $taskName,
                basename(str_replace('\\', '/', $serviceId)),
                $serviceId,
            ];
        }

        $io->table(['Task', 'Handler class', 'Service ID'], $rows);
        $io->success(sprintf('%d task(s) registered.', count($taskMap)));

        return Command::SUCCESS;
    }
}
