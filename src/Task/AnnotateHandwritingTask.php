<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Annotates OCR text to distinguish handwritten from printed text,
 * and marks low-confidence words.
 *
 * Takes the ocr_mistral output (per-page markdown) and the document image,
 * then asks the LLM to:
 *   - Wrap handwritten portions in <hw>...</hw> tags
 *   - Append <?> after words it is uncertain about
 *
 * Must run after ocr_mistral in the pipeline.
 *
 * For single images: sends both the image and OCR text to the LLM.
 * For PDFs: sends text only (LLMs cannot accept PDF as image input).
 */
final class AnnotateHandwritingTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.mistral_vision')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string
    {
        return 'annotate_handwriting';
    }

    public function supports(array $inputs, array $context = []): bool
    {
        return ($inputs['image_url'] ?? '') !== '';
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        $ocrResult = $priorResults['ocr_mistral'] ?? null;
        if (!$ocrResult) {
            return ['error' => 'ocr_mistral must run before annotate_handwriting'];
        }

        $imageUrl = $inputs['image_url'] ?? null;
        $isPdf    = $imageUrl && preg_match('/\.pdf(\?.*)?$/i', $imageUrl);
        $pages    = $ocrResult['raw_response']['pages'] ?? [];

        // Single-page items (typically images, not PDFs)
        if (count($pages) <= 1) {
            $text = $ocrResult['text'] ?? '';
            // For images, send the image; for PDFs, text-only
            $annotated = $this->annotateText($text, $isPdf ? null : $imageUrl);
            return [
                'pages' => [
                    ['index' => 0, 'annotated_text' => $annotated],
                ],
                'annotated_text' => $annotated,
            ];
        }

        // Multi-page: annotate each page individually, text-only
        $annotatedPages = [];
        $fullAnnotated  = [];

        foreach ($pages as $page) {
            $pi       = $page['index'] ?? count($annotatedPages);
            $markdown = $page['markdown'] ?? '';

            if (trim($markdown) === '') {
                $annotatedPages[] = ['index' => $pi, 'annotated_text' => ''];
                continue;
            }

            // Text-only for PDFs; no way to send individual page images
            // without knowing the split page file paths (which are app-level)
            $annotated = $this->annotateText($markdown, null);
            $annotatedPages[] = ['index' => $pi, 'annotated_text' => $annotated];
            $fullAnnotated[]  = $annotated;
        }

        return [
            'pages'          => $annotatedPages,
            'annotated_text' => implode("\n\n---\n\n", $fullAnnotated),
        ];
    }

    private function annotateText(string $ocrText, ?string $imageUrl): string
    {
        $tplContext = $this->promptContext(
            ['image_url' => $imageUrl],
            [],
            [],
        );
        $tplContext['ocr_text'] = $ocrText;

        $taskSlug     = $this->getTask();
        $systemPrompt = trim($this->twig->render(
            "@SurvosAiPipeline/prompt/{$taskSlug}/system.html.twig", $tplContext
        ));
        $userPrompt = trim($this->twig->render(
            "@SurvosAiPipeline/prompt/{$taskSlug}/user.html.twig", $tplContext
        ));

        // Only attach image if it's an actual image URL (not PDF)
        if ($imageUrl !== null && !preg_match('/\.pdf(\?.*)?$/i', $imageUrl)) {
            $userMessage = Message::ofUser($userPrompt, $this->fetchImage($imageUrl));
        } else {
            $userMessage = Message::ofUser($userPrompt);
        }

        $messages = new MessageBag(Message::forSystem($systemPrompt), $userMessage);
        $result   = $this->agent->call($messages);
        $content  = $result->getContent();

        if (is_array($content)) {
            return $content['annotated_text'] ?? json_encode($content);
        }

        return (string) $content;
    }
}
