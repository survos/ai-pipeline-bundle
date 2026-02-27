<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the classify task.
 */
final class ClassifyResult implements \JsonSerializable
{
    public function __construct(
        /**
         * Primary document/object type.
         * e.g. letter, postcard, photograph, map, patch, newspaper_clipping,
         *      certificate, receipt, telegram, book_page, envelope, other
         */
        public readonly string $type,

        /** Confidence score 0.0–1.0 */
        public readonly float $confidence,

        /**
         * Optional secondary / sub-type.
         * e.g. type=patch, subtype=flap; type=photograph, subtype=portrait
         */
        public readonly ?string $subtype = null,

        /** One-sentence rationale for the classification. */
        public readonly ?string $rationale = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type'       => $this->type,
            'subtype'    => $this->subtype,
            'confidence' => $this->confidence,
            'rationale'  => $this->rationale,
        ];
    }
}
