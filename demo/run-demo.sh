#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# SurvosAiPipelineBundle demo
#
# Runs three tasks against a public IIIF image:
#   1. Tesseract OCR (local binary, no API key needed)
#   2. Mistral OCR   (requires MISTRAL_API_KEY)
#   3. basic_description + keywords (requires OPENAI_API_KEY)
#
# Usage:
#   cd /path/to/your/symfony/app
#   bash lib/ai-pipeline-bundle/demo/run-demo.sh
#
# Results are persisted to lib/ai-pipeline-bundle/demo/ as JSON so re-runs
# skip already-completed tasks.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

IMG_URL="https://iiif.digitalcommonwealth.org/iiif/2/commonwealth:pz50hp570/full/,1200/0/default.jpg"
DEMO_DIR="$(cd "$(dirname "$0")" && pwd)"
IMG_FILE="$DEMO_DIR/demo.jpg"
CONSOLE="${CONSOLE:-bin/console}"

# ── Colours ───────────────────────────────────────────────────────────────────
bold=$'\e[1m'; reset=$'\e[0m'; cyan=$'\e[36m'; yellow=$'\e[33m'; green=$'\e[32m'

header() { echo; echo "${bold}${cyan}══ $1 ══${reset}"; echo; }

# ── 0. Download demo image ────────────────────────────────────────────────────
if [[ ! -f "$IMG_FILE" ]]; then
    echo "${yellow}Downloading demo image…${reset}"
    curl -sL "$IMG_URL" -o "$IMG_FILE"
    echo "  Saved to $IMG_FILE"
    echo "  $(identify "$IMG_FILE" 2>/dev/null | awk '{print $3, $5}' || file "$IMG_FILE")"
fi

# ── 1. Tesseract OCR (local, no API key) ──────────────────────────────────────
header "Step 1 — Tesseract OCR (local binary)"
echo "Image: $IMG_FILE"
echo
TESS_OUT=$(tesseract "$IMG_FILE" stdout 2>/dev/null || true)
if [[ -z "$TESS_OUT" ]]; then
    echo "(No text detected — image contains photographs rather than printed text)"
else
    echo "$TESS_OUT"
fi

# ── 2. Mistral OCR via pipeline ───────────────────────────────────────────────
header "Step 2 — Mistral OCR (ai:pipeline:run --tasks=ocr_mistral)"
echo "Subject: $IMG_URL"
echo
$CONSOLE ai:pipeline:run "$IMG_URL" \
    --tasks=ocr_mistral \
    --store=json \
    --store-dir="$DEMO_DIR" \
    --pretty \
    --no-ansi

# ── 3. Vision description + keywords ─────────────────────────────────────────
header "Step 3 — Description & keywords (ai:pipeline:run --tasks=basic_description,keywords)"
echo "Subject: $IMG_URL"
echo "(ocr_mistral result reused from JSON store — no extra API call)"
echo
$CONSOLE ai:pipeline:run "$IMG_URL" \
    --tasks=basic_description,keywords \
    --store=json \
    --store-dir="$DEMO_DIR" \
    --pretty \
    --no-ansi

echo
echo "${bold}${green}Demo complete.${reset}"
echo "Results persisted at: $DEMO_DIR/$(echo -n "$IMG_URL" | sha1sum | cut -d' ' -f1).json"
