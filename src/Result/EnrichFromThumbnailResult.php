<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Result;

/**
 * Result of a single-pass thumbnail enrichment call.
 *
 * Confidence levels are explicit and per-field — the AI is encouraged to
 * guess intelligently as long as it labels its guesses.
 *
 * Confidence scale:
 *   high   — clearly visible or certain from context
 *   medium — strongly suggested by visual evidence
 *   low    — plausible inference, not directly visible
 *
 * Speculative observations are kept separate from factual tags so they
 * can be displayed/indexed differently. A cataloguer can promote or reject them.
 *
 * Example speculative observation:
 *   "Highly likely part of an SS Allgemeine uniform, late WWII period —
 *    collar insignia and cut match 1944-45 pattern."
 *
 * dense_summary ≤350 chars: combines image + known metadata for chatbot/meili.
 */
final class EnrichFromThumbnailResult implements \JsonSerializable
{
    public function __construct(
        /** dcterms:title — concise name if not already known */
        public readonly ?string $title            = null,
        public readonly string  $titleConfidence  = 'high',

        /** dcterms:description — 1-3 sentences of what is physically visible */
        public readonly ?string $description      = null,

        /**
         * Keywords where uncertainty is encoded in the term itself:
         *   no suffix  = directly visible, certain        ("folk costume")
         *   term?      = strongly suggested               ("Budapest?", "1960s?")
         *   term??     = plausible inference only          ("harvest ritual??", "SS uniform??")
         *
         * Always include basis when ? or ?? is used — this is what makes
         * uncertain tags valuable rather than noise, and lets a cataloguer
         * promote or reject them with evidence.
         *
         * Format: [['term' => string, 'basis' => ?string]]
         *
         * @var array<array{term: string, basis?: string|null}>
         */
        public readonly array   $keywords         = [],

        /** Named or described people visible */
        public readonly array   $people           = [],

        /** Places with confidence */
        public readonly array   $places           = [],

        /** ContentType: photograph, postcard, map, manuscript, object, etc. */
        public readonly ?string $contentType      = null,
        public readonly string  $contentTypeConfidence = 'high',

        /**
         * Approximate date — explicitly labeled as guess when uncertain.
         * Format: "1961" (certain), "1960s" (decade guess), "ca. 1920" (approximate)
         */
        public readonly ?string $dateHint         = null,
        public readonly string  $dateConfidence   = 'medium',

        /**
         * Speculative observations — interpretive claims that go beyond
         * what is directly visible, clearly labeled as such.
         *
         * These are valuable for discovery but must be distinguished from facts.
         * A human cataloguer can promote to established fact or reject.
         *
         * Format: [['claim' => string, 'confidence' => float 0-1, 'basis' => string]]
         *
         * Example:
         *   claim:      "Likely SS Allgemeine uniform, late WWII period"
         *   confidence: 0.75
         *   basis:      "Collar insignia and cut match SS pattern 1944-45; death's head visible"
         *
         * @var array<array{claim: string, confidence: float, basis: string}>
         */
        public readonly array   $speculations     = [],

        /**
         * True only if the image contains readable text worth OCRing.
         * Not set for pure photographs, maps without labels, or objects.
         */
        public readonly bool    $hasText          = false,

        /**
         * Information-dense summary ≤350 characters.
         * Combines image observations WITH existing known metadata.
         * Includes hedged language for uncertain elements.
         * This is what the chatbot reads when answering queries.
         *
         * Example: "Fortepan photograph, ca. 1960s (estimated), showing two women
         * in traditional embroidered dress at an outdoor market, likely Budapest —
         * donated by Kovács Péter. Possible folk festival context."
         */
        public readonly ?string $denseSummary     = null,

        /** Overall confidence in the extraction (0.0–1.0) */
        public readonly float   $confidence       = 1.0,
    ) {}

    public function jsonSerialize(): array
    {
        // Group keywords by confidence for tiered indexing
        // high → go into main search index and facets
        // medium → full-text search, shown with softer styling
        // low → searchable but visually distinguished as "suggested"
        $byConf = ['high' => [], 'medium' => [], 'low' => []];
        foreach ($this->keywords as $kw) {
            $term = is_array($kw) ? ($kw['term'] ?? '') : (string)$kw;
            $conf = is_array($kw) ? ($kw['confidence'] ?? 'medium') : 'high';
            if ($term) $byConf[$conf][] = $term;
        }

        return array_filter([
            'title'                   => $this->title,
            'title_confidence'        => $this->title && $this->titleConfidence !== 'high'
                                            ? $this->titleConfidence : null,
            'description'             => $this->description,
            'keywords'                => $this->keywords ?: null,
            // Flat term lists per confidence tier — used by indexers and UI
            'keywords_high'           => $byConf['high']   ?: null,
            'keywords_medium'         => $byConf['medium'] ?: null,
            'keywords_low'            => $byConf['low']    ?: null,
            'people'                  => $this->people     ?: null,
            'places'                  => $this->places     ?: null,
            'content_type'            => $this->contentType,
            'content_type_confidence' => $this->contentTypeConfidence !== 'high'
                                            ? $this->contentTypeConfidence : null,
            'date_hint'               => $this->dateHint,
            'date_confidence'         => $this->dateHint ? $this->dateConfidence : null,
            'speculations'            => $this->speculations ?: null,
            'has_text'                => $this->hasText  ?: null,
            'dense_summary'           => $this->denseSummary,
            'confidence'              => $this->confidence < 1.0 ? $this->confidence : null,
        ], static fn($v) => $v !== null && $v !== [] && $v !== false);
    }

    public function needsOcr(): bool { return $this->hasText; }

    /** All keyword terms regardless of confidence (for full-text search) */
    public function allKeywords(): array
    {
        return array_values(array_filter(array_map(
            static fn($kw) => is_array($kw) ? ($kw['term'] ?? null) : (string)$kw,
            $this->keywords
        )));
    }

    /** Keywords by confidence level — use for tiered indexing */
    public function keywordsByConfidence(string $level): array
    {
        return array_values(array_filter(array_map(
            static fn($kw) => is_array($kw) && ($kw['confidence'] ?? 'medium') === $level
                ? $kw['term'] : null,
            $this->keywords
        )));
    }

    public function applyTo(object $enrichment): void
    {
        if (method_exists($enrichment, 'applyAiEnrichment')) {
            $enrichment->applyAiEnrichment($this->jsonSerialize());
        }
    }
}
