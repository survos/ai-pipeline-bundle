<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\OcrResult;
use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Standard vision-model OCR.
 *
 * Uses gpt-4o (vision) via structured output to extract text with basic
 * block-level layout.  For more sophisticated layout analysis, use OcrMistralTask.
 *
 * Requires: inputs['image_url']
 */
final class OcrTask implements AiTaskInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.ocr')]
        private readonly AgentInterface $agent,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getTask(): string
    {
        return 'ocr';
    }

    public function supports(array $inputs, array $context = []): bool
    {
        return ($inputs['image_url'] ?? '') !== '';
    }

    public function getMeta(): array
    {
        return [
            'agent'         => 'ocr',
            'platform'      => 'openai',
            'model'         => 'gpt-4o',
            'system_prompt' => 'You are an expert OCR engine. Extract every character of text visible '
                . 'in the image, preserving line breaks and paragraph structure. '
                . 'Return structured JSON only — no commentary.',
        ];
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $imageUrl = $inputs['image_url'] ?? throw new \RuntimeException('OcrTask requires inputs["image_url"]');

        $messages = new MessageBag(
            Message::forSystem(
                'You are an expert OCR engine. Your sole job is to extract every character of text visible '
                . 'in the image as accurately as possible, preserving line breaks and paragraph structure. '
                . 'Return structured JSON only — no commentary.'
            ),
            Message::ofUser(
                'Please extract all text from this image. '
                . 'Identify the language, estimate your confidence, and divide the text into logical blocks '
                . '(headings, paragraphs, captions, tables, etc.).',
                $this->fetchImage($imageUrl),
            ),
        );

        $result  = $this->agent->call($messages, ['response_format' => OcrResult::class]);
        $content = $result->getContent();

        $data = match (true) {
            $content instanceof OcrResult        => $content->jsonSerialize(),
            $content instanceof \JsonSerializable => $content->jsonSerialize(),
            \is_array($content)                  => $content,
            default                              => ['text' => (string) $content, 'language' => null, 'confidence' => 'low', 'blocks' => []],
        };

        $tokenUsage = $result->getMetadata()->get('token_usage');
        if ($tokenUsage !== null) {
            $data['_tokens'] = [
                'prompt'     => $tokenUsage->getPromptTokens(),
                'completion' => $tokenUsage->getCompletionTokens(),
                'total'      => $tokenUsage->getTotalTokens(),
                'cached'     => $tokenUsage->getCachedTokens(),
            ];
        }

        return $data;
    }

    private function fetchImage(string $url): Image
    {
        if (str_starts_with($url, 'file://')) {
            $path   = substr($url, 7);
            $binary = file_get_contents($path);
            if ($binary === false) {
                throw new \RuntimeException("Cannot read local image: {$path}");
            }
            $mime = mime_content_type($path) ?: 'image/jpeg';
            return new Image($binary, $mime);
        }

        $response    = $this->httpClient->request('GET', $url);
        $binary      = $response->getContent();
        $contentType = $response->getHeaders()['content-type'][0] ?? 'image/jpeg';
        $mime        = trim(explode(';', $contentType)[0]);

        return new Image($binary, $mime);
    }
}
