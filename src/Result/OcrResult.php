<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Structured output from the OCR task.
 *
 * Used as the `response_format` class for symfony/ai structured output.
 */
final class OcrResult implements \JsonSerializable
{
    public function __construct(
        /** Full plain-text content of the image, with line breaks preserved. */
        public readonly string $text,

        /** ISO 639-1 language code detected in the text, e.g. "en", "de", "ru". Null if no text. */
        public readonly ?string $language,

        /** Confidence that the OCR result is complete and accurate. */
        public readonly string $confidence,   // high | medium | low

        /**
         * Individual text blocks, left-to-right, top-to-bottom.
         * Each entry: { "text": "...", "type": "heading|paragraph|caption|table|other" }
         *
         * @var array<array{text: string, type: string}>
         */
        public readonly array $blocks = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'text'       => $this->text,
            'language'   => $this->language,
            'confidence' => $this->confidence,
            'blocks'     => $this->blocks,
        ];
    }
}
