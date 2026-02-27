# SurvosAiPipelineBundle

A Symfony bundle for running **ordered, stateful AI task pipelines** against any subject — image URLs, text blobs, child-entity results, scraped HTML, song lyrics, or anything else.

## Why this exists

`symfony/ai-bundle` gives you agents, platforms, and tool calling. What it does not give you is a **pipeline**: a sequence of dependent tasks where each step can consume the outputs of previous steps, skip gracefully when inputs are missing, and resume from a checkpoint when re-run.

| Concern | `symfony/ai-bundle` | `SurvosAiPipelineBundle` |
|---|---|---|
| Send a message to an LLM | `ai:agent:call`, `ai:platform:invoke` | — (use symfony/ai directly) |
| Define reusable agents with system prompts | `ai.yaml` agents | — (use symfony/ai directly) |
| Run a named task against a subject | — | `AiTaskInterface` |
| Chain tasks so later ones use earlier results | — | `AiPipelineRunner` |
| Skip tasks when required inputs are absent | — | `supports(array $inputs)` |
| Resume a partially-completed pipeline | — | `ResultStoreInterface` (json or Doctrine) |
| Inspect registered tasks at compile time | — | `ai:pipeline:tasks` |
| Run tasks interactively from CLI | — | `ai:pipeline:run` |

### The core idea

A **task** receives a bag of named inputs (`image_url`, `text`, `child_results`, `html`, …) plus the accumulated results of tasks that already ran in this pipeline pass. It does one thing, returns a JSON-serializable array, and declares whether it can run given the available inputs.

A **pipeline** is just an ordered list of task names. The runner executes them in order, passing prior results forward. Tasks that return `supports() = false` are skipped gracefully.

This means the same runner handles:

- A **3-page letter + 2 photos** folder: each page runs `ocr → classify → people_and_places`; the folder runs `summarize_from_children → generate_title` with no image at all.
- A **website database**: `scrape → extract_metadata → keywords → summarize` — no images involved.
- A **music archive**: `transcribe_lyrics → detect_language → translate` — pure text pipeline.
- A **scanned archive image**: `ocr_mistral → classify → context_description → generate_title → keywords`.

---

## Installation

```bash
composer require survos/ai-pipeline-bundle
```

Register the bundle in `config/bundles.php`:

```php
Survos\AiPipelineBundle\SurvosAiPipelineBundle::class => ['all' => true],
```

Add minimal configuration:

```yaml
# config/packages/survos_ai_pipeline.yaml
survos_ai_pipeline:
    store_dir: '%kernel.project_dir%/var/ai-results'   # for JsonFileResultStore
```

---

## Defining a task

Implement `AiTaskInterface` and register it as a service (autoconfiguration handles the tagging):

```php
use Survos\AiPipelineBundle\Task\AiTaskInterface;

final class SummarizeTask implements AiTaskInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.summarize')]
        private readonly AgentInterface $agent,
    ) {}

    public function getTask(): string
    {
        return 'summarize';   // stable string key used in pipeline definitions and result storage
    }

    public function supports(array $inputs, array $context = []): bool
    {
        // Can summarize if we have OCR text or a description from a prior task
        return isset($inputs['text'])
            || isset($inputs['image_url'])
            || array_key_exists('basic_description', $context['prior_results'] ?? []);
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $text = $priorResults['ocr_mistral']['text']
            ?? $priorResults['ocr']['text']
            ?? $priorResults['basic_description']['description']
            ?? $inputs['text']
            ?? throw new \RuntimeException('Nothing to summarize');

        // … call agent, return array
        return ['summary' => $result->getContent()];
    }

    public function getMeta(): array
    {
        return ['agent' => 'summarize', 'platform' => 'openai', 'model' => 'gpt-4o-mini'];
    }
}
```

The task name returned by `getTask()` is determined at **compile time** — the compiler pass calls `getTask()` via `newInstanceWithoutConstructor()`, which is safe as long as `getTask()` returns a constant string (it always should).

If for some reason that cannot work (e.g. trait-based classes), add an explicit tag attribute in `services.yaml`:

```yaml
App\Ai\Task\MySomethingTask:
    tags:
        - { name: ai_pipeline.task, task: my_something }
```

---

## Inputs vs prior results vs context

| Parameter | What it is | Examples |
|---|---|---|
| `$inputs` | Named inputs for this pipeline run | `image_url`, `text`, `html`, `mime` |
| `$priorResults` | Outputs of tasks that ran before this one | `['ocr' => ['text' => '…']]` |
| `$context` | Caller-supplied metadata, stable across all tasks | `['collection' => 'BSA', 'locale' => 'de']` |

`supports()` receives `$inputs` and `$context`. It does **not** receive `$priorResults` — that would create an ordering dependency in the wrong place. If a task needs a prior result to decide whether to run, list it as a prerequisite in your pipeline definition and let the runner order tasks correctly.

---

## Running pipelines

### From CLI (development / one-off)

```bash
# List all registered tasks (compiled at container build time)
bin/console ai:pipeline:tasks

# Run all tasks against an image URL (in-memory store)
bin/console ai:pipeline:run https://example.com/scan.jpg

# Run specific tasks
bin/console ai:pipeline:run https://example.com/scan.jpg --tasks=ocr,classify,summarize

# Persist results to JSON (allows resuming if the run is interrupted)
bin/console ai:pipeline:run https://example.com/scan.jpg --store=json --pretty

# Interactive loop — keep prompting for new subjects
bin/console ai:pipeline:run --store=json --loop

# Run against a text blob instead of a URL
bin/console ai:pipeline:run "Four score and seven years ago…" --tasks=translate,summarize
```

### From PHP (Doctrine entities, Symfony Messenger, etc.)

```php
use Survos\AiPipelineBundle\Storage\ArrayResultStore;
use Survos\AiPipelineBundle\Task\AiPipelineRunner;

// In a service or message handler:
public function __construct(private readonly AiPipelineRunner $runner) {}

public function process(string $imageUrl, array $context = []): array
{
    $store = new ArrayResultStore(
        subject: $imageUrl,
        inputs:  ['image_url' => $imageUrl, 'mime' => 'image/jpeg'],
    );

    $queue = ['ocr_mistral', 'classify', 'generate_title', 'keywords'];
    $this->runner->runAll($store, $queue);

    return $store->getAllPrior();  // ['ocr_mistral' => […], 'classify' => […], …]
}
```

For Doctrine entities, use the `DoctrineResultStore` from `survos/media-bundle` (or write your own `ResultStoreInterface` implementation):

```php
use Survos\MediaBundle\Storage\DoctrineResultStore;

$store = new DoctrineResultStore($asset, $entityManager);
$this->runner->runAll($store, ['ocr_mistral', 'layout', 'summarize']);
// Results are persisted to the entity's JSON columns automatically.
```

---

## Result store implementations

| Class | Where results live | Use case |
|---|---|---|
| `ArrayResultStore` | In-memory (lost on process exit) | Tests, one-off CLI runs, Messenger handlers that flush to DB themselves |
| `JsonFileResultStore` | `var/ai-results/{sha1}.json` | Development, incremental reruns, CLI demos |
| `DoctrineResultStore` _(media-bundle)_ | Entity JSON columns via ORM | Production — results survive across requests |

All implement `ResultStoreInterface`. Write your own to store results anywhere (Redis, S3, etc.).

---

## Relationship to symfony/ai-bundle

`SurvosAiPipelineBundle` **depends on** `symfony/ai-bundle`. It does not replace it.

- Agents, platforms, and tool-calling are defined and configured in `symfony/ai-bundle` (`ai.yaml`).
- Tasks call agents via `AgentInterface` injected through the normal Symfony DI.
- The pipeline bundle adds the **task registry**, **runner**, **result storage**, and **CLI commands** that turn individual agent calls into a resumable, ordered workflow.

Think of `symfony/ai-bundle` as the engine and `SurvosAiPipelineBundle` as the transmission — it controls which tasks fire, in which order, what each task receives, and where the results go.

---

## Commands

### `ai:pipeline:tasks`

Lists all tasks registered in the compiled container. Zero service instantiation — reads the compile-time map.

```
$ bin/console ai:pipeline:tasks

AI Pipeline Task Registry  (compiled at container build time)
=============================================================

 ----------------------- -------------------------- ---------------------------------
  Task                    Handler class              Service ID
 ----------------------- -------------------------- ---------------------------------
  basic_description       BasicDescriptionTask       App\Ai\Task\BasicDescriptionTask
  classify                ClassifyTask               App\Ai\Task\ClassifyTask
  extract_metadata        ExtractMetadataTask        App\Ai\Task\ExtractMetadataTask
  generate_title          GenerateTitleTask          App\Ai\Task\GenerateTitleTask
  keywords                KeywordsTask               App\Ai\Task\KeywordsTask
  layout                  LayoutTask                 App\Ai\Task\LayoutTask
  ocr                     OcrTask                    App\Ai\Task\OcrTask
  ocr_mistral             OcrMistralTask             App\Ai\Task\OcrMistralTask
  people_and_places       PeopleAndPlacesTask        App\Ai\Task\PeopleAndPlacesTask
  summarize               SummarizeTask              App\Ai\Task\SummarizeTask
  transcribe_handwriting  TranscribeHandwritingTask  App\Ai\Task\TranscribeHandwritingTask
  translate               TranslateTask              App\Ai\Task\TranslateTask
 ----------------------- -------------------------- ---------------------------------

 [OK] 12 task(s) registered.
```

### `ai:pipeline:run`

Runs tasks against a subject. Useful for development, debugging prompts, and ad-hoc enrichment.

```
Usage:
  ai:pipeline:run [<subject>] [options]

Arguments:
  subject       Primary input — image URL, text, or other subject

Options:
  -t, --tasks=TASKS       Comma-separated task names, "all", or "pick" (interactive) [default: "all"]
  -s, --store=STORE       memory or json [default: "memory"]
      --store-dir=DIR     Directory for json store
  -l, --loop              Prompt for another subject after each run
  -p, --pretty            Pretty-print full JSON results after each task
  -v                      Show task name + inputs summary before each task runs
  -vv                     Show full inputs and prior-result keys before each task
      --pause             Pause and wait for Enter before each task (implies -vv style output)
```

```bash
# Interactive task picker — choose which tasks to run from a checklist
bin/console ai:pipeline:run https://example.com/scan.jpg --tasks=pick

# Verbose: show inputs before each task
bin/console ai:pipeline:run https://example.com/scan.jpg -v --tasks=ocr_mistral,classify

# Very verbose: show full input bag + prior-result keys
bin/console ai:pipeline:run https://example.com/scan.jpg -vv --store=json

# Step-through mode: pause before each task for debugging
bin/console ai:pipeline:run https://example.com/scan.jpg --pause --store=json --pretty
```

---

## Demo

The demo script at `demo/run-demo.sh` runs three tasks against a public IIIF image
from the Digital Commonwealth archive — a "Stars & Stripes" Burbee Gum trading card.

```bash
# from your Symfony project root
bash lib/ai-pipeline-bundle/demo/run-demo.sh
```

Results are persisted as JSON so re-runs skip already-completed tasks.

### The image

**Source:** [commonwealth:pz50hp570](https://www.digitalcommonwealth.org/search/commonwealth:pz50hp570)
(Digital Commonwealth / Massachusetts Collections Online)

![Stars & Stripes Burbee Gum trading card](https://iiif.digitalcommonwealth.org/iiif/2/commonwealth:pz50hp570/full/,600/0/default.jpg)

---

### Step 1 — Tesseract OCR (local binary, no API key)

```bash
tesseract demo/demo.jpg stdout
```

```
(No text detected — image contains photographs rather than printed text)
```

Tesseract is a page-scanner: it works well on clean printed documents but
struggles with product photography, mixed layouts, or images at an angle.
This is why Mistral OCR (Step 2) is the preferred path for complex scans.

---

### Step 2 — Mistral OCR

```bash
bin/console ai:pipeline:run \
  "https://iiif.digitalcommonwealth.org/iiif/2/commonwealth:pz50hp570/full/,1200/0/default.jpg" \
  --tasks=ocr_mistral \
  --store=json \
  --store-dir=lib/ai-pipeline-bundle/demo \
  --pretty
```

```
AI Pipeline Runner
==================

Running 1 task(s) against: https://…/commonwealth:pz50hp570/full/,1200/0/default.jpg

  ocr_mistral                    done
{
    "text": "STARS & STRIPES\nTHE\nBURBEE GUM\nSTARS & STRIPES\nA COMPOSITIVE GUM #000115",
    "language": null,
    "confidence": "high",
    "blocks": [
        {
            "text": "STARS & STRIPES\nTHE\nBURBEE GUM\nSTARS & STRIPES\nA COMPOSITIVE GUM #000115",
            "type": "page",
            "index": 0
        }
    ]
}

Results
-------
  ocr_mistral    STARS & STRIPES / THE / BURBEE GUM / STARS & STRIPES / A COMPOSITIVE GUM #000115

// Saved to: lib/ai-pipeline-bundle/demo/e3fac887…json
```

Mistral OCR also returns bounding-box coordinates for two embedded images
and the full document dimensions — useful for `layout` task.

The sub-images can be cropped from the source with ImageMagick using the returned coordinates:

```bash
# img-0: x=83–972, y=562–1036  (left gum box, Stars & Stripes)
convert demo/demo.jpg -crop 889x474+83+562 +repage demo/img-0.jpeg

# img-1: x=1028–1742, y=222–982  (right gum box, Love Is)
convert demo/demo.jpg -crop 714x760+1028+222 +repage demo/img-1.jpeg
```

| img-0 (Stars & Stripes box) | img-1 (Love Is box) |
|---|---|
| ![img-0](demo/img-0.jpeg) | ![img-1](demo/img-1.jpeg) |

---

### Step 3 — Description & keywords

The second run reuses the cached `ocr_mistral` result from the JSON store — no extra
Mistral API call. Only `basic_description` and `keywords` are sent to the vision model.

```bash
bin/console ai:pipeline:run \
  "https://iiif.digitalcommonwealth.org/iiif/2/commonwealth:pz50hp570/full/,1200/0/default.jpg" \
  --tasks=basic_description,keywords \
  --store=json \
  --store-dir=lib/ai-pipeline-bundle/demo \
  --pretty
```

```
Skipping already-completed: (none new)

Running 2 task(s) against: https://…/commonwealth:pz50hp570/full/,1200/0/default.jpg

  basic_description              done
{
    "description": "The image features two different brands of bubble gum packaging.
On the left, the 'Stars & Stripes' packaging is predominantly blue and orange,
with red, white, and blue themes, featuring text that reads \"STARS & STRIPES\"
and a price of \"10¢\". On the right, the 'Love Is' packaging has a black background
with vibrant floral designs in pink and yellow. Multiple unwrapped or partially
opened gum packages are scattered around. The background is a solid yellow color,
contributing to a bright and playful aesthetic.",
    "language": "en",
    "physicalAttributes": [
        "two different packaging designs for bubble gum",
        "left packaging: blue and orange with red, white, and blue color scheme",
        "right packaging: black with floral patterns in pink and yellow",
        "gum pieces in various colors scattered on the surface",
        "solid yellow background"
    ]
}
  keywords                       done
{
    "keywords": [
        "bubble-gum",
        "packaging",
        "cardboard",
        "colorful",
        "1960s",
        "bright",
        "nostalgia",
        "snack"
    ],
    "safety": "safe"
}

Results
-------
  ocr_mistral        STARS & STRIPES / THE / BURBEE GUM …
  basic_description  The image features two different brands of bubble gum packaging …
  keywords           bubble-gum, packaging, cardboard, colorful, 1960s, bright, nostalgia, snack

// Saved to: lib/ai-pipeline-bundle/demo/e3fac887…json
```

The `_tokens` key in each result tracks API usage — useful for cost monitoring.
Token counts are stripped from `$priorResults` before passing to downstream tasks
to avoid inflating context with metadata.

---

## Pipeline Viewer

`demo/viewer.html` is a zero-dependency static HTML page that visualises any
`JsonFileResultStore` result file in a browser.

```
demo/
├── viewer.html          ← the viewer
├── {sha1}.json          ← result file written by ai:pipeline:run --store=json
├── img-0.jpeg           ← artifact extracted from Mistral OCR bbox data
└── img-1.jpeg
```

Open it with a `url` querystring pointing at the subject:

```
# Serve the demo directory (any static server works)
python3 -m http.server 8900 --directory lib/ai-pipeline-bundle/demo

# Then open:
http://localhost:8900/viewer.html?url=https://iiif.digitalcommonwealth.org/iiif/2/commonwealth:pz50hp570/full/,1200/0/default.jpg
```

The viewer:
- Derives the JSON filename via `sha1(url)` in the browser (Web Crypto API, no server)
- Shows a task sidebar with done/skipped/failed badges
- Renders each task's fields (text, description, keywords, confidence, etc.) in a readable layout
- Shows token usage (prompt + completion + cached) as a compact pill
- **Detects artifacts** — if a task result contains `raw_response.pages[].images[]`
  (Mistral OCR bbox output), it shows the cropped sub-images inline.
  Each sub-image links to `viewer.html?url={artifact_src}` so you can open
  *that* sub-image's own pipeline results (if you ran the pipeline on it separately).
- Collapses raw JSON under a `▶ Raw JSON` toggle to keep the view clean

### Artifact support

Artifacts are sub-images (or other derived resources) produced by a task and stored
alongside the JSON result file. Currently detected:

| Source | Format |
|---|---|
| Mistral OCR `raw_response.pages[].images[]` | bbox coordinates + optional base64 |
| Generic `result.artifacts[]` array | `{id, src, annotation}` |

When Mistral returns `image_base64` the viewer uses it directly (no file needed).
When it returns only bbox coordinates, the viewer looks for a sibling file named
`{id}` (e.g. `img-0.jpeg`) in the same directory as the JSON.
