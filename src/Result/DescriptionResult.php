<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the basic_description and context_description tasks.
 */
final class DescriptionResult implements \JsonSerializable
{
    public function __construct(
        /** 1–3 sentence prose description of the image / object. */
        public readonly string $description,

        /** ISO 639-1 language the description was written in. */
        public readonly string $language = 'en',

        /**
         * Physical or visual attributes noticed (material, color, condition, size cues).
         *
         * @var string[]
         */
        public readonly array $physicalAttributes = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'description'        => $this->description,
            'language'           => $this->language,
            'physicalAttributes' => $this->physicalAttributes,
        ];
    }
}
