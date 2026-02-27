<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the keywords task.
 */
final class KeywordsResult implements \JsonSerializable
{
    public function __construct(
        /**
         * Flat list of lower-case, deduped keywords.
         * Prefer concrete nouns, activities, era/style tags.
         *
         * @var string[]
         */
        public readonly array $keywords,

        /**
         * Safety rating of the image content.
         * safe | questionable | unsafe
         */
        public readonly string $safety = 'safe',
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'keywords' => $this->keywords,
            'safety'   => $this->safety,
        ];
    }
}
