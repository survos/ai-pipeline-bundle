<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output for a single vision call that extracts all useful metadata
 * from a photograph in one round-trip.
 *
 * Designed to be cheap: use low-res thumbnails (~1700 tokens) rather than
 * full images (~25000 tokens). The image is sent once; all fields come back
 * in a single structured-output response.
 *
 * Cost at GPT-4o-mini low-res: ~$0.0004/image.
 * Cost at GPT-4o-mini high-res: ~$0.004/image (use only when OCR is needed).
 */
final class ImageEnrichmentResult implements \JsonSerializable
{
    public function __construct(
        /**
         * A concise, descriptive title (≤ 10 words).
         * For Fortepan: describe what is depicted, not the location.
         * e.g. "Children at First Communion ceremony" not "Teleki László tér"
         */
        public readonly ?string $title = null,

        /**
         * 1-3 sentence description of what is shown.
         * Focus on subjects, action, and context visible in the image.
         */
        public readonly ?string $description = null,

        /**
         * 3-8 descriptive keywords for search and faceting.
         * Concrete nouns and activities, not abstract concepts.
         *
         * @var string[]
         */
        public readonly array $keywords = [],

        /**
         * Named people visible or referenced (if identifiable).
         * For historical photos, use descriptive roles if names unknown:
         * "uniformed soldier", "young woman in traditional dress"
         *
         * @var string[]
         */
        public readonly array $people = [],

        /**
         * Places or locations depicted or referenced.
         * Translate to English when possible.
         *
         * @var string[]
         */
        public readonly array $places = [],

        /**
         * Approximate year or decade range.
         * Format: "1961", "1960s", "1955–1965", or null if indeterminate.
         */
        public readonly ?string $dateHint = null,

        /**
         * Whether the image contains significant readable text
         * that would benefit from OCR.
         * true → run OCR pipeline; false → skip.
         */
        public readonly bool $hasText = false,

        /**
         * Confidence 0.0–1.0 in the overall extraction.
         * Low confidence (<0.5) suggests the image may be too dark,
         * blurry, or abstract to extract meaningful metadata.
         */
        public readonly float $confidence = 1.0,
    ) {}

    public function jsonSerialize(): array
    {
        return array_filter([
            'title'       => $this->title,
            'description' => $this->description,
            'keywords'    => $this->keywords ?: null,
            'people'      => $this->people ?: null,
            'places'      => $this->places ?: null,
            'date_hint'   => $this->dateHint,
            'has_text'    => $this->hasText,
            'confidence'  => $this->confidence,
        ], static fn($v) => $v !== null && $v !== [] && $v !== false);
    }
}
