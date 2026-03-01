# Document Extraction Integration — Implementation Spec

## For: `survos/ai-pipeline-bundle`

This document is a coding reference for adding Kreuzberg and improved OCR support to the existing ai-pipeline-bundle. The bundle already has `AiTaskInterface`, `AiPipelineRunner`, result stores, and CLI commands. OCR currently works via individual tasks (`OcrTask` for Tesseract, `OcrMistralTask` for Mistral). This spec adds a proper extraction layer underneath.

---

## Problem

OCR in the bundle is currently task-by-task and image-only. There's no unified way to:

- Extract text from non-image files (PDFs, Word docs, spreadsheets, emails)
- Choose the right extraction backend based on document type
- Get chunking, embeddings, keywords, or metadata alongside text
- Route documents: Kreuzberg for cheap/fast local extraction, Mistral OCR for AI-powered handwriting/damaged scans

---

## What to Build

### 1. `DocumentExtractorInterface`

A service abstraction over extraction backends. Not an `AiTaskInterface` — this is lower-level infrastructure that tasks call.

```php
namespace Survos\AiPipelineBundle\Extractor;

interface DocumentExtractorInterface
{
    public function supports(string $mimeType, array $options = []): bool;

    /**
     * @return ExtractionResult with content, tables, metadata, chunks, keywords
     */
    public function extract(string $source, array $options = []): ExtractionResult;

    public function getName(): string;
}
```

`$source` is a file path, URL, or base64 string. `$options` carries config like OCR language, chunking params, force_ocr, etc.

### 2. `ExtractionResult` Value Object

```php
namespace Survos\AiPipelineBundle\Extractor;

final class ExtractionResult
{
    public function __construct(
        public readonly string $content,           // extracted text
        public readonly string $mimeType,          // detected mime
        public readonly array $metadata = [],      // page_count, author, language, etc.
        public readonly array $tables = [],        // structured table data
        public readonly array $chunks = [],        // chunked text segments
        public readonly array $keywords = [],      // extracted keywords
        public readonly array $images = [],        // extracted/detected images (base64 or paths)
        public readonly ?string $markdown = null,  // markdown representation if available
        public readonly ?float $confidence = null,  // OCR confidence if applicable
        public readonly string $extractor = '',    // which backend produced this
    ) {}

    public function toArray(): array
    {
        return array_filter(get_object_vars($this), fn($v) => $v !== null && $v !== [] && $v !== '');
    }
}
```

### 3. Concrete Extractors

#### `KreuzbergExtractor`

Calls the Kreuzberg REST API (Docker service at configurable endpoint).

```php
namespace Survos\AiPipelineBundle\Extractor;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class KreuzbergExtractor implements DocumentExtractorInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,     // from bundle config: '%env(KREUZBERG_URL)%'
        private int $timeout = 120,
    ) {}

    public function getName(): string { return 'kreuzberg'; }

    public function supports(string $mimeType, array $options = []): bool
    {
        // Kreuzberg handles 75+ formats. Reject only if explicitly routed elsewhere.
        // Does NOT handle: handwriting-heavy scans (route to mistral)
        return !($options['force_mistral'] ?? false);
    }

    public function extract(string $source, array $options = []): ExtractionResult
    {
        // POST /extract with file upload
        // Parse JSON response into ExtractionResult
        // Handle chunking/keywords options if Kreuzberg supports them server-side
    }
}
```

Key endpoints to call:
- `POST /extract` — main extraction (file upload)
- `GET /health` — connectivity check
- `GET /info` — version/capabilities

#### `MistralOcrExtractor`

Calls Mistral OCR 3 API. Use for images/scans where AI-powered recognition matters.

```php
namespace Survos\AiPipelineBundle\Extractor;

final class MistralOcrExtractor implements DocumentExtractorInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,      // '%env(MISTRAL_API_KEY)%'
        private string $model = 'mistral-ocr-latest',
    ) {}

    public function getName(): string { return 'mistral_ocr'; }

    public function supports(string $mimeType, array $options = []): bool
    {
        // Only images and PDFs
        return str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
    }

    public function extract(string $source, array $options = []): ExtractionResult
    {
        // Call POST https://api.mistral.ai/v1/ocr
        // model: mistral-ocr-latest
        // document: { type: "image_url" | "document_url", ... }
        //
        // If $options['annotation_schema'] is set, add:
        //   document_annotation_format and/or bbox_annotation_format
        //   This enables structured JSON extraction (summaries, fields, image descriptions)
        //   Costs $3/1000 pages instead of $2/1000
        //
        // Parse markdown + images + tables into ExtractionResult
    }
}
```

#### `TesseractExtractor`

Wraps local Tesseract binary. Cheapest, fastest for clean printed text.

```php
namespace Survos\AiPipelineBundle\Extractor;

final class TesseractExtractor implements DocumentExtractorInterface
{
    public function getName(): string { return 'tesseract'; }

    public function supports(string $mimeType, array $options = []): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    public function extract(string $source, array $options = []): ExtractionResult
    {
        // shell_exec tesseract with hocr output for confidence scoring
        // Parse hOCR for per-word confidence
        // If avg confidence < threshold, set metadata['needs_llm_ocr'] = true
    }
}
```

### 4. `ExtractorRegistry`

Collects all extractors, picks the right one based on mime type and options.

```php
namespace Survos\AiPipelineBundle\Extractor;

final class ExtractorRegistry
{
    /** @param iterable<DocumentExtractorInterface> $extractors */
    public function __construct(
        #[TaggedIterator('ai_pipeline.extractor')]
        private iterable $extractors,
        private string $defaultExtractor = 'kreuzberg',
    ) {}

    public function get(string $name): DocumentExtractorInterface { /* ... */ }

    public function resolve(string $mimeType, array $options = []): DocumentExtractorInterface
    {
        // 1. If options['extractor'] is set explicitly, use that
        // 2. If options['force_mistral'], use mistral_ocr
        // 3. Try default extractor
        // 4. Fall back to first that supports() this mime type
    }

    /** @return array<string, DocumentExtractorInterface> */
    public function all(): array { /* ... */ }
}
```

### 5. Updated Pipeline Tasks

Replace the existing `OcrTask` / `OcrMistralTask` with a single `ExtractTask` that uses the registry, plus keep specialized tasks for specific AI enrichment.

#### `ExtractTask` (replaces OcrTask + OcrMistralTask)

```php
final class ExtractTask implements AiTaskInterface
{
    public function __construct(
        private ExtractorRegistry $extractors,
    ) {}

    public function getTask(): string { return 'extract'; }

    public function supports(array $inputs, array $context = []): bool
    {
        return isset($inputs['file_path']) || isset($inputs['image_url']) || isset($inputs['file_url']);
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $mimeType = $inputs['mime'] ?? 'application/octet-stream';
        $source = $inputs['file_path'] ?? $inputs['image_url'] ?? $inputs['file_url'];

        $extractor = $this->extractors->resolve($mimeType, $context);
        $result = $extractor->extract($source, $context);

        return $result->toArray();
    }
}
```

#### `OcrQualityCheckTask`

Runs after `extract` when Tesseract was used. Checks confidence and flags for re-extraction via Mistral.

```php
final class OcrQualityCheckTask implements AiTaskInterface
{
    public function getTask(): string { return 'ocr_quality_check'; }

    public function supports(array $inputs, array $context = []): bool
    {
        return true; // always available, checks prior results at runtime
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $extract = $priorResults['extract'] ?? null;
        if (!$extract || ($extract['extractor'] ?? '') !== 'tesseract') {
            return ['status' => 'skipped', 'reason' => 'not tesseract extraction'];
        }

        $confidence = $extract['confidence'] ?? 1.0;
        $needsLlm = $confidence < ($context['ocr_quality_threshold'] ?? 0.7);

        return [
            'confidence' => $confidence,
            'needs_llm_ocr' => $needsLlm,
            'recommendation' => $needsLlm ? 'rerun with mistral_ocr' : 'tesseract output acceptable',
        ];
    }
}
```

#### `ReExtractTask`

Conditional re-extraction with a better backend when quality check fails.

```php
final class ReExtractTask implements AiTaskInterface
{
    public function __construct(
        private ExtractorRegistry $extractors,
    ) {}

    public function getTask(): string { return 're_extract'; }

    public function supports(array $inputs, array $context = []): bool
    {
        return true; // decides at runtime based on prior results
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $quality = $priorResults['ocr_quality_check'] ?? null;
        if (!($quality['needs_llm_ocr'] ?? false)) {
            return ['status' => 'skipped', 'reason' => 'quality acceptable'];
        }

        $source = $inputs['file_path'] ?? $inputs['image_url'] ?? $inputs['file_url'];
        $extractor = $this->extractors->get('mistral_ocr');
        $result = $extractor->extract($source, array_merge($context, ['force_mistral' => true]));

        return $result->toArray();
    }
}
```

### 6. Bundle Configuration

Add to the existing `SurvosAiPipelineExtension`:

```yaml
survos_ai_pipeline:
    store_dir: '%kernel.project_dir%/var/ai-results'

    extractors:
        kreuzberg:
            enabled: true
            endpoint: '%env(KREUZBERG_URL)%'
            timeout: 120
        mistral_ocr:
            enabled: true
            api_key: '%env(MISTRAL_API_KEY)%'
            model: 'mistral-ocr-latest'
        tesseract:
            enabled: true
            binary: 'tesseract'
            language: 'eng'
            psm: 3

    extraction:
        default_extractor: kreuzberg
        ocr_quality_threshold: 0.7          # below this, flag for re-extraction
        handwriting_extractor: mistral_ocr  # override for handwritten material
```

### 7. DI Wiring

In the compiler pass or extension, for each enabled extractor:

- Register the service with the `ai_pipeline.extractor` tag
- Inject config (endpoint, api_key, etc.)
- Register `ExtractorRegistry` with the tagged iterator

New tasks (`ExtractTask`, `OcrQualityCheckTask`, `ReExtractTask`) are autoconfigured via the existing `ai_pipeline.task` tag.

### 8. CLI Additions

#### `ai:extractor:health`

Check connectivity to all configured extraction backends.

```
$ bin/console ai:extractor:health

 Extraction Backends
 ====================
  kreuzberg    ✓ healthy (v4.3.8) at https://kreuzberg.survos.com
  mistral_ocr  ✓ api key valid
  tesseract    ✓ tesseract 5.3.4 (eng, fra, deu)
```

#### `ai:extractor:extract`

One-shot extraction for testing, bypasses the pipeline.

```
$ bin/console ai:extractor:extract document.pdf
$ bin/console ai:extractor:extract document.pdf --extractor=kreuzberg --pretty
$ bin/console ai:extractor:extract scan.jpg --extractor=mistral_ocr --annotate
```

---

## Shape Detection — Document Boundary Discovery

"Shape" is document-level analysis: given N page images, what are the logical documents?

A folder of 30 scans might be 8 letters, 3 photos, and a newspaper clipping. Shape detection figures out where each document starts and ends, what type it is, and how the documents relate (a letter and its reply, an enclosure, etc.).

### Why This Is a Separate Concern

Page-level extraction (OCR, metadata) runs per-image. Shape runs on the **batch** — it needs all pages' text, dates, layout types, and visual cues. This is multi-page reasoning.

### Value Objects

```php
namespace Survos\AiPipelineBundle\Shape;

final class ShapeAnalysis
{
    public function __construct(
        public readonly array $documents,                  // DocumentShape[]
        public readonly int $totalPages,
        public readonly ?string $collectionType = null,    // 'correspondence', 'mixed', 'serial'
        public readonly array $relationships = [],         // DocumentRelationship[]
    ) {}
}

final class DocumentShape
{
    public function __construct(
        public readonly string $id,               // generated: 'letter-1943-03-12'
        public readonly string $type,             // 'letter', 'photograph', 'clipping', 'envelope', 'receipt'
        public readonly array $pageNumbers,       // [1, 2, 3] — which scans belong to this doc
        public readonly ?string $date = null,
        public readonly ?string $author = null,
        public readonly ?string $recipient = null,
        public readonly ?string $title = null,
        public readonly ?string $language = null,
        public readonly float $confidence = 0.0,
        public readonly array $evidence = [],     // ['date change', 'salutation detected', 'handwriting change']
    ) {}
}

final class DocumentRelationship
{
    public function __construct(
        public readonly string $fromId,
        public readonly string $toId,
        public readonly string $type,     // 'reply_to', 'enclosure', 'same_series', 'translation_of'
    ) {}
}
```

### `ShapeDetectorTask`

Runs AFTER per-page extraction. Consumes all page results, produces the boundary map.

```php
final class ShapeDetectorTask implements AiTaskInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.shape_detector')]
        private readonly AgentInterface $agent,
    ) {}

    public function getTask(): string { return 'detect_shape'; }

    public function supports(array $inputs, array $context = []): bool
    {
        return isset($inputs['pages']) && count($inputs['pages']) > 1;
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        // Build per-page summary: text preview, dates, salutations/closings,
        // layout type, blank score, handwriting style hints
        //
        // Send to LLM with structured output requesting DocumentShape[]:
        //   1. Identify boundaries (where does one letter end, another begin?)
        //   2. Classify each document (letter, photo, envelope, clipping, receipt)
        //   3. Extract key fields (date, author, recipient)
        //   4. Detect relationships (reply_to, enclosure, same_series)
        //   5. Provide evidence for each boundary decision
    }
}
```

### Boundary Heuristics (Cheap Pre-scoring)

Before calling the LLM, a `BoundaryHintPreprocessor` runs free/cheap signals:

| Signal | Indicates | Cost |
|--------|-----------|------|
| Date change in OCR text | New document likely | Free (regex) |
| Salutation ("Dear...", "My darling...") | Letter start | Free (regex) |
| Closing ("Sincerely", "Your loving") | Letter end | Free (regex) |
| Blank/near-blank page | Document separator | Free (pixel analysis) |
| Layout change (text → photo → text) | Different document type | Free (from extract metadata) |
| Handwriting style change | Different author | Needs vision model |
| Envelope detected | Separator + metadata | Needs vision model |
| Paper/background color change | Physical boundary | Free (OpenCV in browser) |

These scored hints get passed to the LLM to reduce reasoning and improve accuracy.

### Phased Pipelines

Shape-aware pipelines run in phases:

**Phase 1 — Per-page** (parallelizable):
```
extract → ocr_quality_check → re_extract (if needed)
```

**Phase 2 — Batch** (needs all page results):
```
detect_shape
```

**Phase 3 — Per-document** (parallelizable, grouped by detected shape):
```
summarize → generate_title → keywords → people_and_places
```

### File Additions

```
src/Shape/
├── ShapeAnalysis.php              # NEW
├── DocumentShape.php              # NEW
├── DocumentRelationship.php       # NEW
└── BoundaryHintPreprocessor.php   # NEW — cheap heuristic pre-scoring
src/Task/
└── ShapeDetectorTask.php          # NEW
```

---

## Standard Pipelines

Define these as named pipeline presets in config or as constants:

```php
// Common pipeline sequences
class Pipelines
{
    // Single scanned image: extract → quality check → maybe re-extract → describe → title → keywords
    const SCAN = ['extract', 'ocr_quality_check', 're_extract', 'basic_description', 'generate_title', 'keywords'];

    // Digital document (PDF/Word/etc): extract → summarize → keywords
    const DOCUMENT = ['extract', 'summarize', 'keywords'];

    // Handwritten material: extract with mistral → transcribe → translate
    const HANDWRITTEN = ['extract', 'transcribe_handwriting', 'translate'];

    // Compound document (magazine page): extract → layout → per-section extraction
    const COMPOUND = ['extract', 'layout', 'classify', 'summarize', 'keywords'];

    // Archival folder (30 scans = N logical documents):
    //   Phase 1: per-page extraction
    //   Phase 2: detect document boundaries across all pages
    //   Phase 3: per-document enrichment
    const ARCHIVAL = [
        'phase1' => ['extract', 'ocr_quality_check', 're_extract'],
        'phase2' => ['detect_shape'],
        'phase3' => ['summarize', 'generate_title', 'keywords', 'people_and_places'],
    ];
}
```

Usage:

```bash
bin/console ai:pipeline:run scan.jpg --tasks=scan --store=json
bin/console ai:pipeline:run report.pdf --tasks=document --store=json
```

---

## File Inventory (new/modified)

```
src/
├── Extractor/
│   ├── DocumentExtractorInterface.php    # NEW
│   ├── ExtractionResult.php              # NEW
│   ├── ExtractorRegistry.php             # NEW
│   ├── KreuzbergExtractor.php            # NEW
│   ├── MistralOcrExtractor.php           # NEW
│   └── TesseractExtractor.php            # NEW
├── Shape/
│   ├── ShapeAnalysis.php                 # NEW
│   ├── DocumentShape.php                 # NEW
│   ├── DocumentRelationship.php          # NEW
│   └── BoundaryHintPreprocessor.php      # NEW
├── Task/
│   ├── ExtractTask.php                   # NEW (replaces OcrTask + OcrMistralTask)
│   ├── OcrQualityCheckTask.php           # NEW
│   ├── ReExtractTask.php                 # NEW
│   └── ShapeDetectorTask.php             # NEW
├── DependencyInjection/
│   ├── Configuration.php                 # MODIFY — add extractors config tree
│   └── SurvosAiPipelineExtension.php     # MODIFY — register extractor services
├── Command/
│   ├── ExtractorHealthCommand.php        # NEW — ai:extractor:health
│   └── ExtractCommand.php                # NEW — ai:extractor:extract
└── SurvosAiPipelineBundle.php            # MODIFY — add compiler pass for extractor tags
```

---

## Environment Variables

```dotenv
KREUZBERG_URL=https://kreuzberg.survos.com
MISTRAL_API_KEY=your-key-here
```

---

## Implementation Order

1. `ExtractionResult` + `DocumentExtractorInterface` — the contracts
2. `TesseractExtractor` — simplest, no HTTP, validates the interface
3. `KreuzbergExtractor` — HTTP client to Docker service
4. `MistralOcrExtractor` — Mistral API integration
5. `ExtractorRegistry` — collects and resolves extractors
6. `ExtractTask` — pipeline task using the registry
7. Bundle config + DI wiring
8. `ai:extractor:health` + `ai:extractor:extract` commands
9. `OcrQualityCheckTask` + `ReExtractTask` — smart routing
10. Shape value objects (`ShapeAnalysis`, `DocumentShape`, `DocumentRelationship`)
11. `BoundaryHintPreprocessor` — cheap regex/pixel heuristics
12. `ShapeDetectorTask` — LLM-powered boundary detection
13. Named pipeline presets (SCAN, DOCUMENT, HANDWRITTEN, ARCHIVAL)

---

## Key Decisions

- **Extractors are NOT AiTaskInterface** — they're infrastructure services. Tasks call extractors. This keeps the task layer clean and lets extractors be reused outside pipelines.
- **Kreuzberg is the default** — free, fast, handles 75+ formats. Mistral OCR is the upgrade path for handwriting/damaged scans. Tesseract is the no-dependency fallback.
- **ExtractionResult is a value object** — immutable, serializable, same shape regardless of backend. Tasks don't need to know which extractor ran.
- **Quality-based routing** — Tesseract first (free), check confidence, re-extract with Mistral only if needed. Saves API costs.
- **No PHP FFI for Kreuzberg** — REST API via HttpClient is simpler than managing ext-ffi in PHP-FPM. Kreuzberg runs as a Docker sidecar.
