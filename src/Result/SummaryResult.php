<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the summarize task.
 */
final class SummaryResult implements \JsonSerializable
{
    public function __construct(
        /** 2–4 sentence prose summary suitable for a catalogue or finding aid. */
        public readonly string $summary,

        /** ISO 639-1 language the summary was written in. */
        public readonly string $language = 'en',
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'summary'  => $this->summary,
            'language' => $this->language,
        ];
    }
}
