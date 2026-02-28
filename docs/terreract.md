# Tesseract OCR as a Symfony AI Tool (plan + repo placement)

(Also, see https://docs.kreuzberg.dev/guides/docker/)
## Goal

Make “OCR” feel like a first-class **Symfony AI Tool** (Symfony/AI v0.5.x) so your pipelines and agents can call:

- `ocr_extract(...)`

…without caring whether the implementation is:

1) **Local binary** (`tesseract` installed on the host), or  
2) **Remote HTTP** (a Dockerized tesseract-server, or your own FastAPI wrapper)

This eliminates the “hacky” split where OCR is handled outside the same AI orchestration surface.

## Where should this live?

### Recommended long-term: a dedicated tool package
Create a standalone repo/package:

- `survos/ai-tesseract-tool` (repo: `github.com/survos/ai-tesseract-tool`)

**Why:**
- Clean separation: OCR tool is reusable beyond `ai-pipeline-bundle`
- Versioning independently from pipelines
- Lets other bundles/apps depend on it without pulling pipeline logic
- Mirrors Symfony’s own approach: tools often ship as separate packages

### Short-term (OK now): put it inside `ai-pipeline-bundle`
Yes, you *can* ship it in `survos/ai-pipeline-bundle` initially.

**Why it’s okay:**
- You just published it; you want velocity
- It keeps early adoption simple (“install one bundle”)
- You can later extract it with minimal changes if you keep boundaries clean

**Key rule if you keep it there temporarily:**
Structure it as if it’s a standalone package so extraction is trivial later:
- isolate the tool class
- isolate config
- avoid tight coupling to pipeline internals

## Proposed packaging decision

### Phase 1 (now)
Add tool under `ai-pipeline-bundle`:

- `src/Tool/TesseractOcrTool.php`
- `src/Ocr/Transport/*` (local + http)
- `config/services.php` (autowire + parameters)
- `README.md` section “OCR Tool (Tesseract)”

### Phase 2 (later)
Extract to `survos/ai-tesseract-tool`:
- Copy the isolated folder tree + DI config
- Add a tiny bundle class (if needed) or plain services config (preferred)
- Deprecate the in-bundle tool in `ai-pipeline-bundle` (or keep as “meta dependency”)

If you isolate it well, extraction is a straight move with namespace + composer rename.

---

## What the tool should look like (public contract)

### Tool name
- `ocr_extract` (primary)
- optional aliases later: `ocr_detect_language`, `ocr_hocr`, etc.

### Input options (keep minimal v1)
Accept one of:
- local file path (edge box)
- URL (object storage public URL, internal minio URL, etc.)
- binary upload is *not* a Tool-friendly pattern; keep it path/URL oriented

Suggested signature:
- `input: string` (path or URL)
- `lang: string = 'eng'`
- `format: string = 'text'` (`text|tsv|hocr|alto` — start with `text|hocr`)
- `psm: ?int = null`
- `oem: ?int = null`
- `dpi: ?int = null` (hint for PDFs / rasterization)
- `pages: ?string = null` (e.g. `"1-3,7"` if you support PDF later)

### Output (JSON-friendly)
Return structured array:

```json
{
  "text": "…",
  "pages": [
    {"page": 1, "text": "…", "mean_confidence": 87.2}
  ],
  "meta": {
    "engine": "tesseract",
    "engine_version": "5.x",
    "lang": "eng",
    "transport": "local|http",
    "duration_ms": 1234
  },
  "artifacts": {
    "hocr": "<html>…</html>"
  }
}
```

Keep `pages` optional if v1 is single-image only. But define the shape early so you don’t break clients.

---

## Transport strategy (how it avoids “hacky”)

### Configuration
Two modes, auto-selected:

1) **HTTP mode** if `TESSERACT_URL` is set
2) else **local binary mode** (requires `tesseract` available)
3) else fail with actionable error

Config parameters:
- `survos_ai_pipeline.ocr.tesseract_url` (nullable)
- `survos_ai_pipeline.ocr.tesseract_bin` (default: `tesseract`)
- `survos_ai_pipeline.ocr.pdftoppm_bin` (default: `pdftoppm`, optional)
- `survos_ai_pipeline.ocr.tmp_dir` (default: `%kernel.cache_dir%/ocr`)

### HTTP transport
- Uses Symfony HttpClient
- Calls something like:
  - `POST {TESSERACT_URL}/ocr`
  - JSON body includes `input` (URL or base64) + options

You can support either:
- “URL fetch” (service fetches from URL)
- “inline base64” (Symfony sends bytes)
URL fetch is simpler for your object storage workflow, but you must handle auth/private URLs if needed.

### Local transport
- Uses Symfony Process to run:
  - `tesseract <input> stdout -l eng --psm 3`
- For PDFs:
  - rasterize first via `pdftoppm -r 300 -png input.pdf out`
  - then OCR each page image and aggregate

Start with images only if you want minimal complexity now; document PDF support as “planned”.

---

## Integration with Symfony AI (how it is “a tool”)

### Tool registration
Implement as a Tool using Symfony AI’s Toolbox attributes (e.g. `#[AsTool]`), so its signature becomes schema and is callable by an Agent. See Symfony AI Agent/Toolbox docs. 

- The tool is then added to the agent toolbox in config.
- Pipelines can either:
  - call it directly (synchronous step)
  - or let the LLM agent decide when to call it

Your pipelines likely want direct invocation (deterministic): OCR is a pipeline primitive, not a reasoning step.

### Two usage patterns

**Pipeline primitive:**
- `OcrStep` calls `TesseractOcrTool::extract()` directly

**Agent tool:**
- Agent can call `ocr_extract` when needed (“read the scan and summarize”)

You can support both without duplication.

---

## Documentation section to add to ai-pipeline-bundle README

### OCR Tool: Tesseract (local or HTTP)

#### Install (local binary)
Ubuntu/Debian:
- `sudo apt-get install -y tesseract-ocr poppler-utils`
- Add language packs as needed:
  - `sudo apt-get install -y tesseract-ocr-eng tesseract-ocr-spa`

Verify:
- `tesseract --version`
- `tesseract --list-langs`

#### Install (Docker / HTTP service)
Provide a `docker compose` snippet:

```yaml
services:
  tesseract:
    image: hertzg/tesseract-server:latest
    ports:
      - "8884:8884"
```

Set env:
- `TESSERACT_URL=http://127.0.0.1:8884`

(Exact port/path depends on the chosen image; treat this as illustrative.)

#### Symfony config
- Add env var `TESSERACT_URL` OR install local `tesseract`.
- Optionally configure defaults in `config/packages/ai_pipeline.yaml`.

#### Output format
Document the JSON contract (`text`, `meta`, `pages`, `artifacts`).

---

## Why putting it in ai-pipeline-bundle is acceptable (for now)

- You want a “one install” experience for early adopters.
- OCR is a core pipeline primitive for ScanStationAI-like ingestion flows.
- If the tool is isolated as described, you can extract to a dedicated repo later with minimal churn.

**Implementation discipline to preserve future extraction:**
- Keep it under a self-contained namespace, e.g. `Survos\AiPipelineBundle\Tool\Ocr\*`
- No dependency on “pipeline runtime internals” beyond a thin interface
- Keep config keys prefixed and stable

---

## Suggested next steps (no implementation now)

1) Add this markdown plan into `docs/ocr-tool.md` in `ai-pipeline-bundle`
2) Add a short README section that points to it
3) When you implement:
   - start with **local image OCR** (fastest)
   - add **HTTP mode** (trivial once interface exists)
   - add **PDF rasterization** later (poppler-utils)
4) When it stabilizes, extract to `survos/ai-tesseract-tool`

That gets you the “smoothness” you want without pretending OCR is an LLM provider.
