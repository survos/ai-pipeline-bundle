<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Survos\AiPipelineBundle\Entity\AiTaskRun;
use Survos\AiPipelineBundle\Enum\AiTaskStatus;
use Survos\AiPipelineBundle\Message\RunAiTaskMessage;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
final class RunAiTaskHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiTaskRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RunAiTaskMessage $message): void
    {
        $run = $this->em->find(AiTaskRun::class, $message->taskRunId);
        if ($run === null) {
            $this->logger->error('RunAiTaskHandler: AiTaskRun {id} not found', ['id' => $message->taskRunId]);
            return;
        }

        if ($run->status !== AiTaskStatus::Pending) {
            $this->logger->info('RunAiTaskHandler: {id} already {status} — skipping', [
                'id' => $message->taskRunId, 'status' => $run->status->value,
            ]);
            return;
        }

        $handler = $this->registry->get($run->taskName);
        if ($handler === null) {
            $run->markSkipped('no registered handler');
            $this->em->flush();
            return;
        }

        $run->markRunning();
        $this->em->flush();

        try {
            $result = $handler->run($message->inputs, $message->priorResults, $message->context);
            $run->markDone($result);
            $this->logger->info('RunAiTaskHandler: {task} done in {ms}ms', [
                'task' => $run->taskName,
                'ms'   => $run->durationMs(),
            ]);
        } catch (\Throwable $e) {
            $run->markFailed($e->getMessage());
            $this->logger->error('RunAiTaskHandler: {task} failed — {err}', [
                'task' => $run->taskName, 'err' => $e->getMessage(),
            ]);
            throw $e; // let Messenger retry policy handle it
        } finally {
            $this->em->flush();
        }
    }
}
