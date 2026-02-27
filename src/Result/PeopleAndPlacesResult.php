<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the people_and_places task.
 */
final class PeopleAndPlacesResult implements \JsonSerializable
{
    public function __construct(
        /**
         * Named individuals identified in the image or its text.
         * Include confidence cues in parentheses when unsure.
         * e.g. ["George Washington", "Abraham Lincoln (possible)"]
         *
         * @var string[]
         */
        public readonly array $people = [],

        /**
         * Named locations, landmarks, or geographic references.
         * e.g. ["Brooklyn Bridge", "Boise, ID", "France"]
         *
         * @var string[]
         */
        public readonly array $places = [],

        /**
         * Named organisations, institutions, units, or groups.
         * e.g. ["BSA Lodge 247", "42nd Infantry Division"]
         *
         * @var string[]
         */
        public readonly array $organisations = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'people'        => $this->people,
            'places'        => $this->places,
            'organisations' => $this->organisations,
        ];
    }
}
