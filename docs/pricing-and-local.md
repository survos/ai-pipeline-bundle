# Image → Text Analysis Tools for CPU-Only Servers (2M images)

## Tier 1 — Free, CPU Instant

| Tool | Install | Speed | Cost | 2M Images | Output |
|------|---------|-------|------|-----------|--------|
| **Tesseract 5** (OCR) | `apt install tesseract-ocr` | 0.5–2s | Free | $0 | Full text, bounding boxes |
| **OpenCV edges** (has-text detector) | `pip install opencv-python` | <0.05s | Free | $0 | Boolean flag |
| **pHash / imagehash** (dedup) | `pip install imagehash` | <0.01s | Free | $0 | Hash, similarity score |
| **Exiftool / Pillow** (metadata) | `pip install Pillow` | <0.01s | Free | $0 | Date, GPS, camera, keywords |

## Tier 2 — Free, CPU ML (zero cost, slow)

| Tool | Install | Speed (CPU) | Cost | 2M Images | Output |
|------|---------|-------------|------|-----------|--------|
| **CLIP / open_clip** (zero-shot tagging) | `pip install open_clip_torch` | ~0.2s | Free | $0 / ~111 CPU-hrs | Tags from your vocab, confidence scores |
| **SigLIP** (better zero-shot) | `pip install transformers` | ~0.2s | Free | $0 / ~111 CPU-hrs | Tags from your vocab, confidence scores |
| **ViT-GPT2** (free-text captions) | `pip install transformers` | ~0.5s | Free | $0 / ~278 CPU-hrs | 1-sentence caption |
| **BLIP original** (better captions) | `pip install transformers` | 1–3s | Free | $0 / ~500–1,600 CPU-hrs | Caption + VQA |
| **moondream2** (tiny VLM) | `pip install moondream` | 2–5s | Free | $0 / ~1,100+ CPU-hrs | Caption, detect, point, count |

## Tier 3 — Low-Cost API (quality + structured output)

| Tool | Model | Latency | ~$/image | 2M Images | Output |
|------|-------|---------|----------|-----------|--------|
| **Gemini 2.0 Flash-Lite** | `gemini-2.0-flash-lite` | 1–2s | ~$0.00008 | ~$160 | Caption, basic keywords |
| **Gemini 2.5 Flash** | `gemini-2.5-flash` | 1–3s | ~$0.0003 | ~$600 | Rich description, keywords, structured JSON |
| **Claude Haiku 4.5** | `claude-haiku-4-5-20251001` | 1–3s | ~$0.0004 | ~$800 | High quality description, JSON, metadata |
| **GPT-4.1 mini (batch)** | `gpt-4.1-mini` | async 24h | ~$0.0005 | ~$1,000 | Description, keywords, structured JSON |
| **GPT-4o mini (batch)** | `gpt-4o-mini` | async 24h | ~$0.001 | ~$2,000 | Strong captions, OCR assist, JSON |
| **GPT-4o (batch)** | `gpt-4o` | async 24h | ~$0.005 | ~$10,000 | Best quality, complex scenes, handwriting |

## Key Notes

- **Recommended hybrid:** Run CLIP/SigLIP locally on everything → only send low-confidence or
  high-value items to API. If 80% tagged locally, API volume drops to 400K → ~$240 at Gemini
  2.5 Flash pricing instead of $1,200.
- **Downsample before any API call** to 512px — cuts API cost 4–8× with minimal quality loss
  for captioning.
- **Gemini 2.0 Flash-Lite** is ~$160 for 2M images — worth prototyping first.
- **moondream2** supports structured queries ("what objects are visible?", "is there
  handwriting?") — maps well to museum metadata schema.
- CPU-hours assume a single core; parallelise across 8 cores to cut wall-clock time 8×.
- CLIP/SigLIP speed improves significantly with batching (`batch_size=32+`).
- OpenAI Batch API = 50% off standard pricing; results returned within 24h.
