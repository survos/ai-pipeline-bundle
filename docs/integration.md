# Integrating ai-pipeline-bundle into a Symfony App

This guide covers how to add `survos/ai-pipeline-bundle` to any Symfony application, with emphasis on Doctrine-backed projects like scanstation where scanned images are stored in S3 and pipeline results need to persist in the database.

---

## 1. Install

```bash
composer require survos/ai-pipeline-bundle
```

Ensure these bundles are registered in `config/bundles.php`:

```php
Survos\AiPipelineBundle\SurvosAiPipelineBundle::class => ['all' => true],
Symfony\AI\AiBundle\AiBundle::class => ['all' => true],
```

---

## 2. Configure AI Agents

The bundle uses `symfony/ai-bundle` agents. Define them in `config/packages/ai.yaml`:

```yaml
ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
        google:
            api_key: '%env(GOOGLE_API_KEY)%'
        mistral:
            api_key: '%env(MISTRAL_API_KEY)%'

    agent:
        # Mistral OCR (used by OcrMistralTask — direct HTTP, not through agent)
        # No agent definition needed for ocr_mistral — it calls the API directly.

        # Vision + handwriting (used by transcribe_handwriting, annotate_handwriting)
        mistral_vision:
            platform: 'ai.platform.google'
            model: 'gemini-2.5-flash'
            prompt:
                text: 'You are an expert at reading historical documents. Return structured JSON only.'
            tools: false

        # Classification (cheap model)
        classify:
            platform: 'ai.platform.google'
            model: 'gemini-2.0-flash-lite'
            prompt:
                text: 'You are an expert document classifier. Return structured JSON only.'
            tools: false

        # Metadata extraction, NER, title, summarize
        metadata:
            platform: 'ai.platform.google'
            model: 'gemini-2.5-flash'
            prompt:
                text: 'You are a specialist archivist extracting structured metadata. Return structured JSON only.'
            tools: false
```

### Provider model options (cost-oriented)

The pipeline task code is provider-agnostic. Most provider changes are config-only (switch `platform` + `model` on the existing agent names).

| Provider | Model | Approx cost / image | 2M images | Typical use |
|---|---|---|---|---|
| Google | `gemini-2.0-flash-lite` | ~$0.00008 | ~$160 | Cheapest bulk captioning/keywords |
| Google | `gemini-2.5-flash` | ~$0.0003 | ~$600 | Better quality structured JSON (recommended baseline) |
| Anthropic | `claude-haiku-4-5-20251001` | ~$0.0004 | ~$800 | Strong structured historical summaries |
| OpenAI | `gpt-4.1-mini` (batch) | ~$0.0005 | ~$1,000 | Strong quality/price if async batch is OK |
| OpenAI | `gpt-4o-mini` (batch) | ~$0.001 | ~$2,000 | Vision + OCR assist |
| OpenAI | `gpt-4o` (batch) | ~$0.005 | ~$10,000 | Highest quality for difficult handwriting/layout |

For this bundle, a practical default is:
- `classify` + `description`: `gemini-2.0-flash-lite`
- `metadata`: `gemini-2.5-flash`
- Keep `ocr_mistral` direct for layout/bounding boxes and difficult scans

Then selectively escalate to Claude/OpenAI on flagged records (high-value collections, low confidence, difficult handwriting).

**Key agent names used by built-in tasks:**

| Agent service ID | Used by tasks | Notes |
|---|---|---|
| `ai.agent.ocr` | `ocr` | Vision OCR model configured in your `ai.yaml` |
| `ai.agent.mistral_vision` | `transcribe_handwriting`, `annotate_handwriting`, `basic_description`, `context_description` | Vision + text |
| `ai.agent.classify` | `classify` | Cheap classification |
| `ai.agent.metadata` | `extract_metadata`, `generate_title`, `keywords`, `people_and_places`, `summarize`, `translate` | Text analysis |
| _(none)_ | `ocr_mistral` | Calls Mistral OCR API directly via HttpClient |

---

## 3. Configure the Bundle

```yaml
# config/packages/survos_ai_pipeline.yaml
survos_ai_pipeline:
    store_dir: '%kernel.project_dir%/var/ai-results'  # for JsonFileResultStore

    # Disable tasks you don't need
    disabled_tasks:
        - context_description
        - translate
```

---

## 4. Built-in Tasks

All tasks implement `AiTaskInterface` and are auto-registered. Each receives `$inputs`, `$priorResults`, and `$context`.

| Task | What it does | Requires | Returns |
|---|---|---|---|
| `ocr_mistral` | Mistral OCR API — per-page markdown + layout blocks + image crops | `image_url` | `text`, `pages[]`, `layout_blocks[]`, `image_blocks[]` |
| `ocr` | GPT-4o vision OCR (structured output) | `image_url` | `text`, `blocks[]`, `language`, `confidence` |
| `classify` | Classifies document type (letter, deed, photo, broadside, etc.) | `image_url` | `type`, `subtype`, `confidence`, `rationale` |
| `basic_description` | Visual description of the image | `image_url` | `description`, `language`, `physicalAttributes[]` |
| `context_description` | Richer description using prior metadata | `image_url` + prior results | `description` |
| `extract_metadata` | Structured metadata (dates, people, places, subjects) | `image_url` or prior OCR | `dateRange`, `people[]`, `places[]`, `subjects[]` |
| `generate_title` | Descriptive archival title | prior OCR or description | `title`, `alternativeTitles[]`, `confidence` |
| `keywords` | Keyword tags | prior OCR or description | `keywords[]`, `safety` |
| `people_and_places` | Named entity extraction | prior OCR | `people[]`, `places[]`, `organisations[]` |
| `summarize` | Concise summary | prior OCR or description | `summary`, `language` |
| `transcribe_handwriting` | Handwriting transcription | `image_url` | `text`, `language`, `confidence`, `blocks[]` |
| `annotate_handwriting` | Marks `<hw>` handwritten / `<?>` uncertain in OCR text | prior `ocr_mistral` | `annotated_text`, `pages[]` |
| `translate` | Translation to English | prior OCR | `translation`, `sourceLanguage` |
| `layout` | Parses OCR markdown into structured layout blocks | prior `ocr_mistral` | `blocks[]` with types and positions |

---

## 5. Running Pipelines

### 5a. From PHP Code (the primary integration path)

```php
use Survos\AiPipelineBundle\Task\AiPipelineRunner;
use Survos\AiPipelineBundle\Storage\ArrayResultStore;

class ScanProcessor
{
    public function __construct(
        private readonly AiPipelineRunner $runner,
    ) {}

    public function process(string $imageUrl): array
    {
        $store = new ArrayResultStore(
            subject: $imageUrl,
            inputs: ['image_url' => $imageUrl],
        );

        $pipeline = ['ocr_mistral', 'classify', 'extract_metadata', 'generate_title'];
        $this->runner->runAll($store, $pipeline);

        return $store->getAllPrior();
        // Returns: ['ocr_mistral' => [...], 'classify' => [...], ...]
    }
}
```

### 5b. With Progress Callbacks

```php
$this->runner->onBeforeTask(function (string $task) {
    $this->logger->info("Starting task: {$task}");
});

$this->runner->onAfterTask(function (string $task, array $result, string $status) {
    // $status is 'done', 'skipped', 'failed', or 'no_handler'
    $this->logger->info("Task {$task}: {$status}");
});

$this->runner->runAll($store, $pipeline);
```

### 5c. Task-by-Task (for fine-grained control)

```php
$queue = ['ocr_mistral', 'classify', 'summarize'];
while ($queue !== []) {
    $taskName = $this->runner->runNext($store, $queue);
    if ($taskName === null) break;

    // Check result, update UI, etc.
    $result = $store->getPrior($taskName);
    if ($result['failed'] ?? false) {
        // Handle failure
        break;
    }
}
```

---

## 6. Database Integration (Doctrine)

For apps with Doctrine entities (like scanstation), you need a `ResultStoreInterface` that reads/writes to entity columns instead of JSON files.

### 6a. The Entity

Add a JSON column (or columns) to your entity for pipeline results:

```php
#[ORM\Entity]
class ScannedPage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $imageUrl;

    /** All pipeline task results, keyed by task name */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aiResults = null;

    /** Pipeline status: pending, processing, complete, failed */
    #[ORM\Column(length: 20)]
    private string $pipelineStatus = 'pending';

    public function getAiResults(): array { return $this->aiResults ?? []; }
    public function setAiResults(?array $results): void { $this->aiResults = $results; }
    public function getAiResult(string $task): ?array { return $this->aiResults[$task] ?? null; }
    public function setAiResult(string $task, array $result): void {
        $results = $this->aiResults ?? [];
        $results[$task] = $result;
        $this->aiResults = $results;
    }
}
```

### 6b. Custom ResultStore

```php
use Survos\AiPipelineBundle\Storage\ResultStoreInterface;

final class DoctrineResultStore implements ResultStoreInterface
{
    public function __construct(
        private readonly ScannedPage $entity,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getSubject(): ?string
    {
        return $this->entity->getImageUrl();
    }

    public function getInputs(): array
    {
        return ['image_url' => $this->entity->getImageUrl()];
    }

    public function getPrior(string $taskName): ?array
    {
        return $this->entity->getAiResult($taskName);
    }

    public function getAllPrior(): array
    {
        return $this->entity->getAiResults();
    }

    public function saveResult(string $taskName, array $result): void
    {
        $this->entity->setAiResult($taskName, $result);
        $this->em->flush();
    }

    public function isLocked(): bool
    {
        return $this->entity->getPipelineStatus() === 'processing';
    }
}
```

### 6c. Usage with Doctrine

```php
class PipelineService
{
    public function __construct(
        private readonly AiPipelineRunner $runner,
        private readonly EntityManagerInterface $em,
    ) {}

    public function processPage(ScannedPage $page, array $pipeline = null): void
    {
        $pipeline ??= ['ocr_mistral', 'classify', 'extract_metadata', 'generate_title'];

        $page->setPipelineStatus('processing');
        $this->em->flush();

        $store = new DoctrineResultStore($page, $this->em);

        // Runner saves results to the entity after each task via flush()
        $this->runner->runAll($store, $pipeline);

        $page->setPipelineStatus('complete');
        $this->em->flush();
    }
}
```

### 6d. With Symfony Messenger (async)

```php
// Message
final readonly class ProcessPageMessage
{
    public function __construct(public int $pageId, public array $pipeline = []) {}
}

// Handler
#[AsMessageHandler]
final class ProcessPageHandler
{
    public function __construct(
        private readonly AiPipelineRunner $runner,
        private readonly EntityManagerInterface $em,
        private readonly ScannedPageRepository $repo,
    ) {}

    public function __invoke(ProcessPageMessage $msg): void
    {
        $page = $this->repo->find($msg->pageId)
            ?? throw new \RuntimeException("Page {$msg->pageId} not found");

        $pipeline = $msg->pipeline ?: ['ocr_mistral', 'classify', 'extract_metadata'];

        $store = new DoctrineResultStore($page, $this->em);
        $this->runner->runAll($store, $pipeline);

        $page->setPipelineStatus('complete');
        $this->em->flush();
    }
}

// Dispatch
$bus->dispatch(new ProcessPageMessage($page->getId(), ['ocr_mistral', 'generate_title']));
```

---

## 7. Custom Tasks

Create a task class that implements `AiTaskInterface` (or extends `AbstractVisionTask` for prompt-template-based tasks):

```php
use Survos\AiPipelineBundle\Task\AbstractVisionTask;

final class DetectWatermarkTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.classify')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'detect_watermark'; }

    public function getMeta(): array
    {
        return ['agent' => 'classify', 'description' => 'Detect watermarks in scanned images'];
    }
}
```

Create prompt templates at:
```
templates/bundles/SurvosAiPipelineBundle/prompt/detect_watermark/system.html.twig
templates/bundles/SurvosAiPipelineBundle/prompt/detect_watermark/user.html.twig
```

The task is auto-registered via autoconfiguration (tagged `ai_pipeline.task`).

---

## 8. Overriding Prompt Templates

Bundle defaults live at `vendor/survos/ai-pipeline-bundle/templates/prompt/{task}/`.

Override any template by placing it at:
```
templates/bundles/SurvosAiPipelineBundle/prompt/{task}/system.html.twig
templates/bundles/SurvosAiPipelineBundle/prompt/{task}/user.html.twig
```

Template variables available:

| Variable | Type | Description |
|---|---|---|
| `imageUrl` | `?string` | The image URL being processed |
| `inputs` | `array` | All named inputs for this run |
| `prior` | `array` | Results of prior tasks (keyed by task name) |
| `ocr_text` | `?string` | Shortcut: OCR text from `ocr_mistral` or `ocr` |
| `type` | `?string` | Shortcut: classified type from `classify` |
| `metadata` | `array` | Shortcut: result of `extract_metadata` |
| `description` | `?string` | Shortcut: description from `basic_description` or `context_description` |
| `title` | `?string` | Shortcut: title from `generate_title` |
| `context` | `array` | Caller-supplied context |

---

## 9. Scanstation Integration Checklist

For an app that scans documents and stores cropped images in S3:

1. **Install**: `composer require survos/ai-pipeline-bundle`
2. **Configure**: `ai.yaml` (agents), `survos_ai_pipeline.yaml` (disabled_tasks)
3. **Entity**: Add `aiResults` JSON column + `pipelineStatus` to your ScannedPage/Asset entity
4. **ResultStore**: Create `DoctrineResultStore` wrapping your entity
5. **Service**: Create a `PipelineService` that takes an entity + pipeline list, runs tasks
6. **Trigger**: After S3 upload completes, dispatch a Messenger message or call the service directly
7. **Pipeline**: Choose tasks based on document type:
   - Handwritten documents: `ocr_mistral → annotate_handwriting → transcribe_handwriting → people_and_places → extract_metadata → generate_title`
   - Printed documents: `ocr_mistral → classify → summarize → keywords`
   - Photos/cards: `ocr_mistral → classify → basic_description → keywords`
8. **Display**: Read results from `$entity->getAiResult('ocr_mistral')['text']` etc. in your Twig templates or API responses
9. **Re-run**: Use `--force` flag or clear specific task results to re-process with updated prompts

---

## 10. Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│  Your App (scanstation, demo, etc.)                 │
│                                                     │
│  ┌─────────────┐   ┌──────────────────────────┐    │
│  │ app:process  │   │ Messenger Handler         │    │
│  │ app:add      │   │ Controller                │    │
│  └──────┬──────┘   └────────────┬──────────────┘    │
│         │                       │                    │
│         └───────┬───────────────┘                    │
│                 ▼                                    │
│  ┌──────────────────────────┐                       │
│  │   AiPipelineRunner       │  (from bundle)        │
│  │   - runNext() / runAll() │                       │
│  │   - onBeforeTask()       │                       │
│  │   - onAfterTask()        │                       │
│  └──────────┬───────────────┘                       │
│             │                                       │
│  ┌──────────▼───────────────┐                       │
│  │   AiTaskRegistry         │  (compiled task map)  │
│  │   - get('ocr_mistral')   │                       │
│  │   - all()                │                       │
│  └──────────┬───────────────┘                       │
│             │                                       │
│  ┌──────────▼───────────────┐                       │
│  │   ResultStoreInterface   │                       │
│  │   ┌─────────────────┐   │                       │
│  │   │ JsonFileResult   │   │  (demo/CLI)           │
│  │   │ ArrayResult      │   │  (tests/one-shot)     │
│  │   │ DoctrineResult   │   │  (production/DB)      │
│  │   └─────────────────┘   │                       │
│  └──────────────────────────┘                       │
│                                                     │
│  ┌──────────────────────────┐                       │
│  │   Tasks (14 built-in)    │                       │
│  │   + your custom tasks    │                       │
│  │                          │                       │
│  │   Each calls symfony/ai  │                       │
│  │   agents via Twig prompt │                       │
│  │   templates              │                       │
│  └──────────────────────────┘                       │
└─────────────────────────────────────────────────────┘
```

---

## 11. Environment Variables

```bash
# Required
GOOGLE_API_KEY=...
MISTRAL_API_KEY=...

# Optional (if using other platforms / fallbacks)
# OPENAI_API_KEY=sk-...
# ANTHROPIC_API_KEY=...
```

Store real keys in `.env.local` (never commit). For CI/CD, use GitHub Secrets or your deployment platform's secret management.
