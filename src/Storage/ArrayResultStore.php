<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Storage;

/**
 * In-memory result store — useful for CLI demos, tests, and one-shot runs.
 * Nothing is persisted beyond the current process.
 */
final class ArrayResultStore implements ResultStoreInterface
{
    private array $results = [];

    /**
     * @param string|null $subject  Primary input (image URL, text blob, etc.)
     * @param array       $inputs   Named inputs available to tasks
     */
    public function __construct(
        private readonly ?string $subject = null,
        private readonly array $inputs = [],
    ) {
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getInputs(): array
    {
        return $this->inputs;
    }

    public function getPrior(string $taskName): ?array
    {
        return $this->results[$taskName] ?? null;
    }

    public function getAllPrior(): array
    {
        return $this->results;
    }

    public function saveResult(string $taskName, array $result): void
    {
        $this->results[$taskName] = $result;
    }

    public function isLocked(): bool
    {
        return false;
    }
}
