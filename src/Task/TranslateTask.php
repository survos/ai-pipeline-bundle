<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Translate the text content of a document to English.
 *
 * A pure text pipeline task — no image required.
 * Reads from priorResults: ocr_mistral > ocr > transcribe_handwriting.
 * Useful for foreign-language material (Soviet Life, German military documents, etc.)
 */
final class TranslateTask implements AiTaskInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        private readonly AgentInterface $agent,
    ) {
    }

    public function getTask(): string
    {
        return 'translate';
    }

    public function supports(array $inputs, array $context = []): bool
    {
        // Only useful when we already have text to translate from a prior task.
        // The runner passes priorResults separately; supports() just declares intent.
        return true;
    }

    public function getMeta(): array
    {
        return [
            'agent'         => 'metadata',
            'platform'      => 'openai',
            'model'         => 'gpt-4o-mini',
            'system_prompt' => 'You are a professional translator specialising in historical documents. '
                . 'Translate the provided text to English, preserving proper nouns, place names, and titles. '
                . 'Note the source language detected. If the text is already in English, return it unchanged.',
        ];
    }

    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        // Prefer Mistral OCR, then standard OCR, then handwriting transcription.
        $sourceText = $priorResults['ocr_mistral']['text']
            ?? $priorResults['ocr']['text']
            ?? $priorResults['transcribe_handwriting']['text']
            ?? null;

        if ($sourceText === null || trim($sourceText) === '') {
            return [
                'translated_text' => null,
                'source_language' => null,
                'skipped'         => true,
                'reason'          => 'No source text available for translation.',
            ];
        }

        $messages = new MessageBag(
            Message::forSystem(
                'You are a professional translator specialising in historical documents. '
                . 'Translate the provided text to English. '
                . 'Preserve proper nouns, place names, and titles in their original form with English explanation in brackets. '
                . 'Note the source language detected. '
                . 'If the text is already in English, return it unchanged and note source_language = "en". '
                . 'Return a JSON object with keys: translated_text (string), source_language (ISO 639-1 code), notes (string or null).'
            ),
            Message::ofUser(
                "Translate the following text to English:\n\n" . mb_substr($sourceText, 0, 4000)
            ),
        );

        $result  = $this->agent->call($messages);
        $content = $result->getContent();

        if (\is_array($content)) {
            return $content;
        }

        $raw   = (string) $content;
        $start = strpos($raw, '{');
        if ($start !== false) {
            $decoded = json_decode(substr($raw, $start), true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'translated_text' => $raw,
            'source_language' => null,
            'notes'           => null,
        ];
    }
}
