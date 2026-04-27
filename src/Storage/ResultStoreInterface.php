<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Storage;

/**
 * Abstraction over where AI pipeline task results are read from and written to.
 *
 * Known implementations:
 *   - ArrayResultStore         in-memory, per-request / CLI one-shots / tests
 *   - JsonFileResultStore      persists to var/ai-results/{key}.json
 *   - DoctrineResultStore      dedicated ai_task_run relational table (queryable, async-dispatchable)
 *
 * Possible future implementations:
 *   - EntityBlobResultStore    stores results as JSON blobs directly on an entity
 *                              (aiCompleted array column) — simpler, no extra table,
 *                              suitable for MongoDB or single-entity workflows where
 *                              queryability per-task is not needed
 *   - RedisResultStore         TTL-based cache, useful for ephemeral batch jobs
 *
 * The "subject" is the primary input to the pipeline — an image URL, a piece
 * of text, an entity ID, etc.  Tasks read it from getSubject() if they need it.
 */
interface ResultStoreInterface
{
    /**
     * The primary subject for this pipeline run.
     * For vision tasks: an image URL.
     * For text tasks: a text blob or null.
     * May be null if all inputs come from prior results or context.
     */
    public function getSubject(): ?string;

    /** Named inputs available to tasks (e.g. ['image_url' => '...', 'html' => '...']). */
    public function getInputs(): array;

    /** Result for a previously completed task, or null. */
    public function getPrior(string $taskName): ?array;

    /** All completed results, keyed by task name. */
    public function getAllPrior(): array;

    /** Persist the result of a completed task. */
    public function saveResult(string $taskName, array $result): void;

    /** Whether another worker has locked this record. */
    public function isLocked(): bool;
}
