<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Storage;

/**
 * File-backed result store — persists results to a JSON file under $storeDir.
 *
 * The filename is derived from a SHA-1 of the subject string, so the same
 * subject always maps to the same file.  This allows incremental reruns.
 */
final class JsonFileResultStore implements ResultStoreInterface
{
    private array $data;
    private readonly string $filePath;

    /**
     * @param string|null $subject  Primary input (image URL, text blob, etc.)
     * @param string      $storeDir Directory to write JSON files into
     * @param array       $inputs   Named inputs available to tasks
     */
    public function __construct(
        private readonly ?string $subject,
        private readonly string $storeDir,
        private readonly array $inputs = [],
    ) {
        $key            = sha1($subject ?? 'no-subject');
        $this->filePath = rtrim($storeDir, '/') . '/' . $key . '.json';
        $this->data     = $this->load();
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
        return $this->data['results'][$taskName] ?? null;
    }

    public function getAllPrior(): array
    {
        return $this->data['results'] ?? [];
    }

    public function saveResult(string $taskName, array $result): void
    {
        $this->data['subject']            = $this->subject;
        $this->data['results'][$taskName] = $result;
        $this->persist();
    }

    public function isLocked(): bool
    {
        return false;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    private function load(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($this->filePath), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function persist(): void
    {
        if (!is_dir($this->storeDir)) {
            mkdir($this->storeDir, 0755, true);
        }
        file_put_contents(
            $this->filePath,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
