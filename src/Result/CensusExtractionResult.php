<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the census table extraction task.
 */
final class CensusExtractionResult implements \JsonSerializable
{
    public function __construct(
        /** Document title or description. */
        public readonly string $title,

        /** Whether tabular data was found. */
        public readonly bool $hasTables,

        /**
         * Array of table objects, each with headers and rows.
         * @var array<array{headers: array<string>, rows: array<array<string>>, notes: string}>
         */
        public readonly array $tables,

        /** Language of the document. */
        public readonly ?string $language,

        /** Confidence level. */
        public readonly string $confidence,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'title'      => $this->title,
            'has_tables' => $this->hasTables,
            'tables'     => $this->tables,
            'language'   => $this->language,
            'confidence' => $this->confidence,
        ];
    }
}
