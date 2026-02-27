<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mistral OCR with document-layout awareness.
 *
 * Uses the Mistral OCR API directly (https://api.mistral.ai/v1/ocr) with
 * the `mistral-ocr-latest` model.
 *
 * Returns:
 *   text          — full markdown text
 *   language      — null (Mistral doesn't return this)
 *   confidence    — 'high'
 *   layout_blocks — array of {type, text, bbox{x,y,width,height}} from document_annotation
 *                   type: headline|subheadline|column|caption|byline|paragraph|image|other
 *                   bbox: normalised to actual image pixel coords (0..imageWidth, 0..imageHeight)
 *   image_blocks  — array of {id, top_left_x, top_left_y, bottom_right_x, bottom_right_y,
 *                   image_base64?, image_annotation?} — embedded images detected by Mistral
 *                   with pixel coords in the actual image space
 *   pages         — raw per-page data (markdown, dimensions, tables, hyperlinks, header, footer)
 *   raw_response  — full API response
 *
 * Requires: inputs['image_url']
 * Optional: inputs['max_pages'] (int, default 0 = all) — limits PDF page range sent to Mistral
 */
final class OcrMistralTask implements AiTaskInterface
{
    /** JSON schema sent as document_annotation_format to get per-block layout */
    private const LAYOUT_SCHEMA = [
        'type'        => 'json_schema',
        'json_schema' => [
            'name'   => 'document_layout',
            'schema' => [
                'type'       => 'object',
                'properties' => [
                    'blocks' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'required'   => ['type', 'text', 'bbox'],
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => ['headline', 'subheadline', 'column', 'caption',
                                               'byline', 'paragraph', 'image', 'advertisement', 'other'],
                                ],
                                'text' => ['type' => 'string'],
                                'bbox' => [
                                    'type'       => 'object',
                                    'required'   => ['x', 'y', 'width', 'height'],
                                    'properties' => [
                                        'x'      => ['type' => 'number'],
                                        'y'      => ['type' => 'number'],
                                        'width'  => ['type' => 'number'],
                                        'height' => ['type' => 'number'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MISTRAL_API_KEY)%')]
        private readonly string $mistralApiKey,
    ) {
    }

    public function getTask(): string
    {
        return 'ocr_mistral';
    }

    public function supports(array $inputs, array $context = []): bool
    {
        $url  = $inputs['image_url'] ?? '';
        $mime = $context['mime'] ?? $inputs['mime'] ?? '';
        if ($url === '') {
            return false;
        }
        if ($mime !== '') {
            return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
        }
        return true;
    }

    public function getMeta(): array
    {
        return [
            'agent'         => 'mistral-ocr-latest',
            'platform'      => 'mistral (direct HTTP)',
            'model'         => 'mistral-ocr-latest',
            'system_prompt' => 'Direct call to https://api.mistral.ai/v1/ocr. '
                . 'Returns markdown, layout blocks (headline/column/etc with bbox), '
                . 'and embedded image crops with pixel coordinates.',
        ];
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $url       = $inputs['image_url'] ?? throw new \RuntimeException('OcrMistralTask requires inputs["image_url"]');
        $maxPages  = (int) ($inputs['max_pages'] ?? $context['max_pages'] ?? 0); // 0 = all pages

        $isPdf = $this->isPdf($url);

        // Build the document payload
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);
            if ($isPdf) {
                $binary = file_get_contents($path);
                if ($binary === false) {
                    throw new \RuntimeException("Cannot read local PDF: {$path}");
                }
                $document = [
                    'type'          => 'document_url',
                    'document_url'  => 'data:application/pdf;base64,' . base64_encode($binary),
                ];
            } else {
                $binary = $this->resizeIfNeeded($path, 3000);
                $document = [
                    'type'      => 'image_url',
                    'image_url' => 'data:image/jpeg;base64,' . base64_encode($binary),
                ];
            }
        } else {
            if ($isPdf) {
                $document = [
                    'type'         => 'document_url',
                    'document_url' => $url,
                ];
            } else {
                $document = [
                    'type'      => 'image_url',
                    'image_url' => $url,
                ];
            }
        }

        $payload = [
            'model'                       => 'mistral-ocr-latest',
            'document'                    => $document,
            'include_image_base64'        => true,
            'document_annotation_format'  => self::LAYOUT_SCHEMA,
        ];

        // Limit pages for PDFs (0-based indices)
        if ($isPdf && $maxPages > 0) {
            $payload['pages'] = range(0, $maxPages - 1);
        }

        $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/ocr', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mistralApiKey,
                'Content-Type'  => 'application/json',
            ],
            'json'    => $payload,
            'timeout' => 300,
        ]);

        $data  = $response->toArray();
        $pages = $data['pages'] ?? [];

        $fullText = implode("\n\n", array_map(
            fn(array $p): string => $p['markdown'] ?? '',
            $pages
        ));

        // Parse layout blocks — Mistral returns document_annotation either at top level
        // (single image) or per-page inside pages[n]['document_annotation'] (PDF).
        // We normalise bbox coords to actual pixel dimensions per page.
        $layoutBlocks = [];

        // Top-level annotation (image / single-page)
        $topAnnotation = $data['document_annotation'] ?? null;
        if (is_string($topAnnotation) && isset($pages[0])) {
            $layoutBlocks = array_merge(
                $layoutBlocks,
                $this->parseAnnotation($topAnnotation, $pages[0], 0)
            );
        }

        // Per-page annotations (PDF multi-page)
        foreach ($pages as $pageData) {
            $pageAnnotation = $pageData['document_annotation'] ?? null;
            if (is_string($pageAnnotation)) {
                $layoutBlocks = array_merge(
                    $layoutBlocks,
                    $this->parseAnnotation($pageAnnotation, $pageData, $pageData['index'] ?? 0)
                );
            }
        }

        // Collect embedded image crops from pages (pixel coords + optional base64)
        $imageBlocks = [];
        foreach ($pages as $page) {
            $pageIndex = $page['index'] ?? 0;
            foreach ($page['images'] ?? [] as $img) {
                $imageBlocks[] = array_filter([
                    'page'           => $pageIndex,
                    'id'             => $img['id'],
                    'top_left_x'     => $img['top_left_x'],
                    'top_left_y'     => $img['top_left_y'],
                    'bottom_right_x' => $img['bottom_right_x'],
                    'bottom_right_y' => $img['bottom_right_y'],
                    'image_base64'   => $img['image_base64'] ?? null,
                    'annotation'     => $img['image_annotation'] ?? null,
                ], fn($v) => $v !== null);
            }
        }

        // Strip base64 from raw_response to keep JSON result file small
        $stripped = $data;
        foreach ($stripped['pages'] ?? [] as $pi => $page) {
            foreach ($page['images'] ?? [] as $ii => $img) {
                unset($stripped['pages'][$pi]['images'][$ii]['image_base64']);
            }
        }

        // ── Helpers ──────────────────────────────────────────────────────────

        return [
            'text'          => trim($fullText),
            'language'      => null,
            'confidence'    => 'high',
            'layout_blocks' => $layoutBlocks,
            'image_blocks'  => $imageBlocks,
            'pages'         => array_map(fn(array $p): array => [
                'index'      => $p['index'],
                'markdown'   => $p['markdown'] ?? '',
                'dimensions' => $p['dimensions'] ?? null,
                'tables'     => $p['tables']     ?? [],
                'header'     => $p['header']     ?? null,
                'footer'     => $p['footer']     ?? null,
            ], $pages),
            'raw_response'  => $stripped,
        ];
    }

    /** True when the URL/path points to a PDF file. */
    private function isPdf(string $url): bool
    {
        return str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? $url), '.pdf');
    }

    /**
     * Parse a document_annotation JSON string (returned by Mistral as a string, not object)
     * and return normalised layout blocks with bbox in actual pixel coords.
     *
     * @param array<string,mixed> $pageData  The page entry from pages[] for dimension lookup
     * @return array<int,array<string,mixed>>
     */
    private function parseAnnotation(string $rawAnnotation, array $pageData, int $pageIndex): array
    {
        $parsed = json_decode($rawAnnotation, true);
        if (!is_array($parsed)) {
            return [];
        }
        $rawBlocks = $parsed['blocks'] ?? [];
        if ($rawBlocks === []) {
            return [];
        }

        $dims = $pageData['dimensions'] ?? null;
        $imgW = $dims['width']  ?? 0;
        $imgH = $dims['height'] ?? 0;

        // Find the bounding box of all blocks in render space to derive scale
        $renderMaxX = 0.0;
        $renderMaxY = 0.0;
        foreach ($rawBlocks as $b) {
            $renderMaxX = max($renderMaxX, (float)(($b['bbox']['x'] ?? 0) + ($b['bbox']['width']  ?? 0)));
            $renderMaxY = max($renderMaxY, (float)(($b['bbox']['y'] ?? 0) + ($b['bbox']['height'] ?? 0)));
        }
        $scaleX = ($renderMaxX > 0 && $imgW > 0) ? $imgW / $renderMaxX : 1.0;
        $scaleY = ($renderMaxY > 0 && $imgH > 0) ? $imgH / $renderMaxY : 1.0;

        $blocks = [];
        foreach ($rawBlocks as $b) {
            $bbox = $b['bbox'] ?? [];
            $blocks[] = [
                'page'   => $pageIndex,
                'type'   => $b['type'] ?? 'other',
                'text'   => $b['text'] ?? '',
                'bbox'   => [
                    'x'      => (int) round(($bbox['x']      ?? 0) * $scaleX),
                    'y'      => (int) round(($bbox['y']      ?? 0) * $scaleY),
                    'width'  => (int) round(($bbox['width']  ?? 0) * $scaleX),
                    'height' => (int) round(($bbox['height'] ?? 0) * $scaleY),
                ],
            ];
        }
        return $blocks;
    }

    /**
     * Return image binary, resized to $maxWidth if wider.
     * Uses GD when available, falls back to raw file read.
     * Always returns JPEG binary.
     */
    private function resizeIfNeeded(string $path, int $maxWidth): string
    {
        if (!extension_loaded('gd')) {
            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException("Cannot read local image: {$path}");
            }
            return $binary;
        }

        [$origW, $origH, $type] = getimagesize($path);

        if ($origW <= $maxWidth) {
            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException("Cannot read local image: {$path}");
            }
            return $binary;
        }

        $scale  = $maxWidth / $origW;
        $newW   = $maxWidth;
        $newH   = (int) round($origH * $scale);

        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default        => imagecreatefromjpeg($path),
        };

        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, 88);
        imagedestroy($dst);
        return ob_get_clean();
    }
}
