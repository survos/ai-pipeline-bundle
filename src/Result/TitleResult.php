<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the generate_title task.
 */
final class TitleResult implements \JsonSerializable
{
    public function __construct(
        /**
         * Short, human-readable title for a catalogue record (≤ 80 chars).
         * e.g. "Letter from Pvt. John Smith, France, 1918"
         */
        public readonly string $title,

        /**
         * Alternative / variant titles (useful for ambiguous material).
         *
         * @var string[]
         */
        public readonly array $alternativeTitles = [],

        /** Confidence: high | medium | low */
        public readonly string $confidence = 'medium',
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'title'             => $this->title,
            'alternativeTitles' => $this->alternativeTitles,
            'confidence'        => $this->confidence,
        ];
    }
}
