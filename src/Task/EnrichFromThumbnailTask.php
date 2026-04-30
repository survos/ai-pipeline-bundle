<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\EnrichFromThumbnailResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Single-pass thumbnail enrichment task.
 *
 * Replaces running these tasks separately:
 *   BasicDescription + Keywords + GenerateTitle + PeopleAndPlaces + Classify
 *
 * Cost rationale:
 *   - The image token cost (~1,700 tokens at 512px low-res) is paid ONCE
 *   - Additional output fields (keywords, people, places, dense_summary)
 *     add only ~100-200 output tokens — essentially free vs the image cost
 *   - Running 5 tasks separately would cost 5× the image parsing fee
 *   - Single call: ~$0.0004  |  5 separate calls: ~$0.0020  (5× more expensive)
 *
 * The dense_summary field is the most valuable output:
 *   - ≤350 characters, information-dense
 *   - Combines image observations with existing known metadata
 *   - Optimised for Meilisearch hybrid search and chatbot context
 *   - This is exactly the "350-char recommended for chat" you described
 *
 * Context parameter (existing metadata):
 *   Pass the current MediaEnrichment::toValueMap() or BaseItemDto::toSourceMeta()
 *   so the AI doesn't waste tokens re-describing what we already know.
 *   Only null/missing fields are filled.
 *
 * Usage:
 *   $result = $runner->run(
 *     subject: $enrichment->imageUrlForAi(512),
 *     task:    'enrich_from_thumbnail',
 *     context: $enrichment->toValueMap(),  // what we already know
 *   );
 *   $enrichment->applyAiEnrichment($result);
 */
final class EnrichFromThumbnailTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.thumbnail_enrich')] AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string
    {
        return 'enrich_from_thumbnail';
    }

    protected function responseFormatClass(): ?string
    {
        return EnrichFromThumbnailResult::class;
    }

    /**
     * Pass existing metadata as context so the AI fills only missing fields.
     * The user prompt template renders these as "already known" so the AI
     * skips them and focuses on what's genuinely missing.
     */
    protected function promptContext(array $inputs, array $priorResults, array $context = []): array
    {
        return array_merge(
            parent::promptContext($inputs, $priorResults, $context),
            [
                // Expose existing metadata explicitly for the user template
                'context' => array_filter(
                    $context,
                    static fn($v) => $v !== null && $v !== '' && $v !== []
                ),
            ]
        );
    }
}
