<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

final class ImageAnalysisResult implements \JsonSerializable
{
    public function __construct(
        public readonly ?string $imageType = null,
        public readonly float $confidence = 0.0,
        public readonly ?string $confidenceNotes = null,
        public readonly ?string $extractedText = null,
        public readonly ?string $languageDetected = null,
        public readonly bool $escalate = false,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'image_type' => $this->imageType,
            'confidence' => $this->confidence,
            'confidence_notes' => $this->confidenceNotes,
            'extracted_text' => $this->extractedText,
            'language_detected' => $this->languageDetected,
            'escalate' => $this->escalate,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }
}
