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
 * the `mistral-ocr-latest` model, which returns bounding boxes, columns,
 * and table structure — far more useful than plain text for complex scans.
 *
 * Requires: inputs['image_url']
 */
final class OcrMistralTask implements AiTaskInterface
{
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
        // Mistral OCR works on images and PDFs accessible by URL.
        // If mime is known, restrict; otherwise allow and let Mistral handle it.
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
            'system_prompt' => 'Direct call to https://api.mistral.ai/v1/ocr (image_url type) — no system prompt. '
                . 'Returns per-page markdown with bounding boxes, columns, and table structure.',
        ];
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $url = $inputs['image_url'] ?? throw new \RuntimeException('OcrMistralTask requires inputs["image_url"]');

        // Build the document payload: local files are sent as base64,
        // remote URLs are passed directly (Mistral fetches them server-side).
        if (str_starts_with($url, 'file://')) {
            $path   = substr($url, 7);
            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException("Cannot read local image for OCR: {$path}");
            }
            $mime     = mime_content_type($path) ?: 'image/jpeg';
            $document = [
                'type'      => 'image_url',
                'image_url' => 'data:' . $mime . ';base64,' . base64_encode($binary),
            ];
        } else {
            $document = [
                'type'      => 'image_url',
                'image_url' => $url,
            ];
        }

        $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/ocr', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mistralApiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'                => 'mistral-ocr-latest',
                'document'             => $document,
                'include_image_base64' => false,
            ],
            'timeout' => 120,
        ]);

        $data  = $response->toArray();
        $pages = $data['pages'] ?? [];

        $fullText = implode("\n\n", array_map(
            fn(array $p): string => $p['markdown'] ?? '',
            $pages
        ));

        return [
            'text'       => trim($fullText),
            'language'   => null,
            'confidence' => 'high',
            'blocks'     => array_map(
                fn(array $p, int $i): array => [
                    'text'  => $p['markdown'] ?? '',
                    'type'  => 'page',
                    'index' => $i,
                ],
                $pages,
                array_keys($pages),
            ),
            'raw_response' => $data,
        ];
    }
}
