<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Base class for AI pipeline tasks that use Twig templates for prompts.
 *
 * Default templates live in the bundle at:
 *   resources/views/prompt/{task_name}/system.html.twig
 *   resources/views/prompt/{task_name}/user.html.twig
 *
 * Override them in your application at:
 *   templates/bundles/SurvosAiPipelineBundle/prompt/{task_name}/system.html.twig
 *   templates/bundles/SurvosAiPipelineBundle/prompt/{task_name}/user.html.twig
 *
 * Inputs bag (common keys):
 *   image_url  — URL of the image (vision tasks)
 *   text       — plain text input (text-only tasks)
 *   html       — HTML input
 *   mime       — MIME type hint
 */
abstract class AbstractVisionTask implements AiTaskInterface
{
    public function __construct(
        protected readonly AgentInterface $agent,
        protected readonly TwigEnvironment $twig,
        protected readonly HttpClientInterface $httpClient,
    ) {}

    // ── Subclass API ──────────────────────────────────────────────────────────

    /**
     * Variables available to both system.html.twig and user.html.twig.
     * Override to add task-specific context.
     *
     * @return array<string, mixed>
     */
    protected function promptContext(array $inputs, array $priorResults, array $context = []): array
    {
        return [
            'imageUrl'    => $inputs['image_url'] ?? null,
            'inputs'      => $inputs,
            'context'     => $context,
            'prior'       => $priorResults,
            'ocr_text'    => $this->ocrText($priorResults),
            'type'        => $this->classifiedType($priorResults),
            'metadata'    => $priorResults['extract_metadata'] ?? [],
            'description' => $priorResults['context_description']['description']
                ?? $priorResults['basic_description']['description']
                ?? null,
            'title'       => $priorResults['generate_title']['title'] ?? null,
        ];
    }

    /**
     * Symfony/AI structured-output class for this task, or null for raw JSON.
     * @return class-string|null
     */
    protected function responseFormatClass(): ?string
    {
        return null;
    }

    // ── AiTaskInterface ───────────────────────────────────────────────────────

    public function supports(array $inputs, array $context = []): bool
    {
        return ($inputs['image_url'] ?? '') !== ''
            || ($inputs['text']      ?? '') !== ''
            || ($inputs['html']      ?? '') !== '';
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $imageUrl   = $inputs['image_url'] ?? null;
        $tplContext = $this->promptContext($inputs, $priorResults, $context);
        $taskSlug   = $this->getTask();

        // Template lookup order:
        //   1. templates/bundles/SurvosAiPipelineBundle/prompt/{slug}/system.html.twig  (app override)
        //   2. @SurvosAiPipeline/prompt/{slug}/system.html.twig  (bundle default)
        $systemPrompt = trim($this->twig->render(
            "@SurvosAiPipeline/prompt/{$taskSlug}/system.html.twig", $tplContext
        ));
        $userPrompt = trim($this->twig->render(
            "@SurvosAiPipeline/prompt/{$taskSlug}/user.html.twig", $tplContext
        ));

        $userMessage = $imageUrl !== null
            ? Message::ofUser($userPrompt, $this->fetchImage($imageUrl))
            : Message::ofUser($userPrompt);

        $messages = new MessageBag(Message::forSystem($systemPrompt), $userMessage);

        $options = [];
        if ($fmt = $this->responseFormatClass()) {
            $options['response_format'] = $fmt;
        }

        $result  = $this->agent->call($messages, $options);
        $content = $result->getContent();

        $data = match (true) {
            $content instanceof \JsonSerializable => $content->jsonSerialize(),
            is_array($content)                   => $content,
            default                              => ['raw' => (string) $content],
        };

        $usage = $result->getMetadata()->get('token_usage');
        if ($usage !== null) {
            $data['_tokens'] = [
                'prompt'     => $usage->getPromptTokens(),
                'completion' => $usage->getCompletionTokens(),
                'total'      => $usage->getTotalTokens(),
                'cached'     => $usage->getCachedTokens(),
            ];
        }

        return $data;
    }

    public function getMeta(): array
    {
        $agentName = null;
        try {
            $rc = new \ReflectionClass(static::class);
            foreach ($rc->getConstructor()?->getParameters() ?? [] as $param) {
                foreach ($param->getAttributes(Autowire::class) as $attr) {
                    $args = $attr->getArguments();
                    $svc  = $args['service'] ?? $args[0] ?? null;
                    if ($svc && str_starts_with((string) $svc, 'ai.agent.')) {
                        $agentName = str_replace('ai.agent.', '', $svc);
                        break 2;
                    }
                }
            }
        } catch (\Throwable) {}

        return [
            'agent'    => $agentName ?? 'unknown',
            'template' => "@SurvosAiPipeline/prompt/{$this->getTask()}/system.html.twig",
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Fetch an image and return it as a base64-encoded Image object.
     *
     * Supports:
     *   - https://... / http://...  fetched via HttpClient
     *   - file:///path/to/file      read directly from the local filesystem
     *
     * Using Image (base64) rather than ImageUrl avoids hotlink-protection 403s
     * when AI provider servers try to fetch protected URLs (e.g. Wikimedia Commons).
     */
    protected function fetchImage(string $url): Image
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
        // Strip quality params: "image/jpeg; charset=..." → "image/jpeg"
        $mime = trim(explode(';', $contentType)[0]);

        return new Image($binary, $mime);
    }

    protected function ocrText(array $priorResults): ?string
    {
        return $priorResults['ocr_mistral']['text']
            ?? $priorResults['ocr']['text']
            ?? null;
    }

    protected function classifiedType(array $priorResults): ?string
    {
        return $priorResults['classify']['type'] ?? null;
    }
}
