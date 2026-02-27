<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Storage\ResultStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Picks tasks off a queue, runs them against a ResultStore, and records results.
 *
 * Storage-agnostic — accepts any ResultStoreInterface.
 * Handlers are resolved from AiTaskRegistry (built at compile time).
 * Does NOT flush Doctrine, dispatch workflows, or touch the database.
 */
final class AiPipelineRunner
{
    public function __construct(
        private readonly AiTaskRegistry $registry,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Optional callback fired just before each task runs.
     * Signature: fn(string $taskName, array $inputs, array $priorResults): void
     *
     * @var callable|null
     */
    private mixed $beforeTask = null;

    /**
     * Optional callback fired just after each task completes (or fails/skips).
     * Signature: fn(string $taskName, array $result, string $status): void
     * $status is one of: 'done', 'skipped', 'failed', 'no_handler'
     *
     * @var callable|null
     */
    private mixed $afterTask = null;

    public function onBeforeTask(callable $cb): static
    {
        $this->beforeTask = $cb;
        return $this;
    }

    public function onAfterTask(callable $cb): static
    {
        $this->afterTask = $cb;
        return $this;
    }

    /**
     * Run the next task from the queue.
     * $queue is mutated in-place (array_shift).
     *
     * @param list<string> $queue
     */
    public function runNext(ResultStoreInterface $store, array &$queue): ?string
    {
        if ($store->isLocked()) {
            $this->logger->info('AiPipelineRunner: store is locked, skipping.');
            return null;
        }

        if ($queue === []) {
            return null;
        }

        $taskName     = array_shift($queue);
        $inputs       = $store->getInputs();
        $priorResults = $this->sanitisedPrior($store->getAllPrior());

        // Merge subject into inputs as 'image_url' for backwards compat with vision tasks
        $subject = $store->getSubject();
        if ($subject !== null && !isset($inputs['image_url'])) {
            $inputs['image_url'] = $subject;
        }

        $handler = $this->registry->get($taskName);

        if ($handler === null) {
            $this->logger->warning('AiPipelineRunner: no handler for task "{task}".', ['task' => $taskName]);
            $result = ['skipped' => true, 'reason' => 'no registered handler'];
            $store->saveResult($taskName, $result);
            if ($this->afterTask !== null) {
                ($this->afterTask)($taskName, $result, 'no_handler');
            }
            return $taskName;
        }

        if (!$handler->supports($inputs, $store->getInputs())) {
            $this->logger->info('AiPipelineRunner: task "{task}" skipped (supports() = false).', ['task' => $taskName]);
            $result = ['skipped' => true, 'reason' => 'not supported'];
            $store->saveResult($taskName, $result);
            if ($this->afterTask !== null) {
                ($this->afterTask)($taskName, $result, 'skipped');
            }
            return $taskName;
        }

        if ($this->beforeTask !== null) {
            ($this->beforeTask)($taskName, $inputs, $priorResults);
        }

        $this->logger->info('AiPipelineRunner: running "{task}".', ['task' => $taskName]);

        try {
            $result = $handler->run($inputs, $priorResults);
            $store->saveResult($taskName, $result);
            if ($this->afterTask !== null) {
                ($this->afterTask)($taskName, $result, 'done');
            }
        } catch (\Throwable $e) {
            $this->logger->error('AiPipelineRunner: "{task}" failed: {error}', [
                'task'  => $taskName,
                'error' => $e->getMessage(),
            ]);
            $result = ['failed' => true, 'error' => $e->getMessage()];
            $store->saveResult($taskName, $result);
            if ($this->afterTask !== null) {
                ($this->afterTask)($taskName, $result, 'failed');
            }
        }

        return $taskName;
    }

    /**
     * Drain the entire queue. Returns task names that were processed.
     *
     * @param list<string> $queue
     * @return list<string>
     */
    public function runAll(ResultStoreInterface $store, array $queue): array
    {
        $ran = [];
        while ($queue !== [] && !$store->isLocked()) {
            $name = $this->runNext($store, $queue);
            if ($name === null) {
                break;
            }
            $ran[] = $name;
        }
        return $ran;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function sanitisedPrior(array $prior): array
    {
        $out = [];
        foreach ($prior as $taskName => $result) {
            unset($result['raw_response'], $result['blocks']);
            if (isset($result['text']) && strlen($result['text']) > 8000) {
                $result['text'] = mb_substr($result['text'], 0, 8000) . "\n[… truncated]";
            }
            $out[$taskName] = $result;
        }
        return $out;
    }
}
