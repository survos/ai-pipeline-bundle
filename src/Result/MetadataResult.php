<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the extract_metadata task.
 */
final class MetadataResult implements \JsonSerializable
{
    public function __construct(
        /**
         * Year or decade range of the document/object.
         * Format: "1943", "1930s", "1920–1935", or null if unknown.
         */
        public readonly ?string $dateRange,

        /**
         * People explicitly named or clearly identifiable in the document.
         *
         * @var string[]
         */
        public readonly array $people = [],

        /**
         * Places referenced in the document (cities, states, countries, landmarks).
         *
         * @var string[]
         */
        public readonly array $places = [],

        /**
         * Broad subject headings, e.g. "World War II", "Boy Scouts of America".
         *
         * @var string[]
         */
        public readonly array $subjects = [],

        /** ISO 639-1 language code of the primary text content. */
        public readonly ?string $language = null,

        /**
         * Any organisation names, institutions, or publishers mentioned.
         *
         * @var string[]
         */
        public readonly array $organisations = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'dateRange'     => $this->dateRange,
            'people'        => $this->people,
            'places'        => $this->places,
            'subjects'      => $this->subjects,
            'language'      => $this->language,
            'organisations' => $this->organisations,
        ];
    }
}
