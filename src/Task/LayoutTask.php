<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Extract the structural layout of a document page: columns, tables, headings,
 * figure captions, and their bounding-box positions.
 *
 * Strategy (in order of preference):
 *   1. If ocr_mistral has already run and its raw_response contains `pages`,
 *      parse the blocks directly — zero extra API cost.
 *   2. Otherwise call the Mistral OCR API fresh (same as OcrMistralTask).
 *
 * Inputs:  inputs['image_url'] (optional — used only for fresh Mistral call)
 *          priorResults['ocr_mistral']['raw_response'] (preferred path)
 */
final class LayoutTask implements AiTaskInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MISTRAL_API_KEY)%')]
        private readonly string $mistralApiKey,
    ) {
    }

    public function getTask(): string
    {
        return 'layout';
    }

    public function supports(array $inputs, array $context = []): bool
    {
        // Can run if we have prior Mistral OCR data OR a direct image URL
        $hasPriorOcr = isset($inputs['prior_results']['ocr_mistral']['raw_response']);
        $hasUrl      = ($inputs['image_url'] ?? '') !== '';
        $mime        = $context['mime'] ?? $inputs['mime'] ?? '';

        if ($hasPriorOcr) {
            return true;
        }

        if (!$hasUrl) {
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
            'system_prompt' => 'Reuses ocr_mistral raw_response if already run (zero API cost). '
                . 'Otherwise calls mistral-ocr-latest. Parses markdown blocks into typed layout regions: '
                . 'heading_1/2/3, paragraph, table, list, figure, blockquote.',
        ];
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        // ── 1. Reuse existing OCR_MISTRAL raw response if available ─────────
        $mistralRaw = $priorResults['ocr_mistral']['raw_response'] ?? null;

        if ($mistralRaw === null) {
            // ── 2. Fresh Mistral OCR call ────────────────────────────────────
            $url = $inputs['image_url'] ?? throw new \RuntimeException(
                'LayoutTask requires either prior ocr_mistral results or inputs["image_url"]'
            );

            $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/ocr', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->mistralApiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'    => 'mistral-ocr-latest',
                    'document' => [
                        'type'         => 'document_url',
                        'document_url' => $url,
                    ],
                    'include_image_base64' => false,
                ],
                'timeout' => 120,
            ]);
            $mistralRaw = $response->toArray();
        }

        $pages = $mistralRaw['pages'] ?? [];

        $regions = [];
        foreach ($pages as $pageIndex => $page) {
            $regions = array_merge($regions, $this->parsePageRegions($page, $pageIndex));
        }

        return [
            'page_count' => count($pages),
            'regions'    => $regions,
            'summary'    => $this->summariseLayout($regions),
        ];
    }

    /**
     * Parse a single Mistral OCR page into typed layout regions.
     *
     * @return array<int, array{type: string, page: int, text: string, bbox: array|null}>
     */
    private function parsePageRegions(array $page, int $pageIndex): array
    {
        $markdown = $page['markdown'] ?? '';
        $regions  = [];
        $blocks   = preg_split('/\n{2,}/', trim($markdown)) ?: [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $type = match (true) {
                str_starts_with($block, '# ')   => 'heading_1',
                str_starts_with($block, '## ')  => 'heading_2',
                str_starts_with($block, '### ') => 'heading_3',
                str_contains($block, '|')       => 'table',
                str_starts_with($block, '> ')   => 'blockquote',
                str_starts_with($block, '- ') || str_starts_with($block, '* ') => 'list',
                preg_match('/^\d+\. /', $block) === 1 => 'ordered_list',
                str_starts_with($block, '![')   => 'figure',
                default                          => 'paragraph',
            };

            $regions[] = [
                'type' => $type,
                'page' => $pageIndex,
                'text' => $block,
                'bbox' => $page['bbox'] ?? null,
            ];
        }

        return $regions;
    }

    private function summariseLayout(array $regions): string
    {
        if (empty($regions)) {
            return 'No layout regions detected.';
        }

        $counts = array_count_values(array_column($regions, 'type'));
        arsort($counts);

        $parts = [];
        foreach ($counts as $type => $count) {
            $parts[] = "{$count} {$type}" . ($count > 1 ? 's' : '');
        }

        return implode(', ', $parts) . '.';
    }
}
