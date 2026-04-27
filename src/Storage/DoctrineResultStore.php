<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Storage;

use Doctrine\ORM\EntityManagerInterface;
use Survos\AiPipelineBundle\Entity\AiTaskRun;
use Survos\AiPipelineBundle\Enum\AiTaskStatus;
use Survos\AiPipelineBundle\Message\RunAiTaskMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ResultStoreInterface backed by the ai_task_run table.
 *
 * Replaces the aiQueue/aiCompleted JSON blobs on entities.
 * Each task is a row — queryable, async-dispatched, retry-able.
 */
final class DoctrineResultStore implements ResultStoreInterface
{
    /** @var \Closure(): ?string */
    private readonly \Closure $imageUrlResolver;

    public function __construct(
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        \Closure $imageUrlResolver,
        private readonly bool $locked = false,
    ) {
        $this->imageUrlResolver = $imageUrlResolver;
    }

    public function getSubject(): ?string
    {
        return ($this->imageUrlResolver)();
    }

    public function getInputs(): array
    {
        $url = $this->getSubject();
        return $url !== null ? ['image_url' => $url] : [];
    }

    public function getPrior(string $taskName): ?array
    {
        $run = $this->findLatestRun($taskName, AiTaskStatus::Done);
        return $run?->result;
    }

    public function getAllPrior(): array
    {
        $runs = $this->em->getRepository(AiTaskRun::class)->findBy([
            'subjectType' => $this->subjectType,
            'subjectId'   => $this->subjectId,
            'status'      => AiTaskStatus::Done,
        ]);

        $out = [];
        foreach ($runs as $run) {
            // Latest result wins when a task has been re-run
            if ($run->result !== null) {
                $out[$run->taskName] = $run->result;
            }
        }
        return $out;
    }

    /**
     * Enqueue a task: insert a pending row and dispatch async.
     * Prior results and context are snapshotted at dispatch time.
     */
    public function enqueue(string $taskName, array $context = []): AiTaskRun
    {
        $run = new AiTaskRun($this->subjectType, $this->subjectId, $taskName);
        $this->em->persist($run);
        $this->em->flush();

        $this->bus->dispatch(new RunAiTaskMessage(
            taskRunId:    (string) $run->id,
            inputs:       $this->getInputs(),
            priorResults: $this->getAllPrior(),
            context:      $context,
        ));

        return $run;
    }

    /**
     * Synchronous save — used by AiPipelineRunner when running inline.
     * For async use enqueue() instead.
     *
     * @param array<string, mixed> $result
     */
    public function saveResult(string $taskName, array $result): void
    {
        $run = $this->findLatestRun($taskName, AiTaskStatus::Running)
            ?? $this->findLatestRun($taskName, AiTaskStatus::Pending)
            ?? new AiTaskRun($this->subjectType, $this->subjectId, $taskName);

        $result = $this->sanitizeForJson($result);

        if (isset($result['skipped'])) {
            $run->markSkipped($result['reason'] ?? 'skipped');
        } elseif (isset($result['failed'])) {
            $run->markFailed($result['error'] ?? 'unknown error');
        } else {
            $run->markDone($result);
        }

        $this->em->persist($run);
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    private function findLatestRun(string $taskName, AiTaskStatus $status): ?AiTaskRun
    {
        return $this->em->getRepository(AiTaskRun::class)->findOneBy(
            ['subjectType' => $this->subjectType, 'subjectId' => $this->subjectId,
             'taskName' => $taskName, 'status' => $status],
            ['queuedAt' => 'DESC'],
        );
    }

    private function sanitizeForJson(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->sanitizeForJson($v);
            }
        }
        return $value;
    }
}
