<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\ImageEnrichmentResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Single-call vision task: send one thumbnail, get back
 * title + description + keywords + people + places + date_hint + has_text.
 *
 * Use low-res thumbnails (~480px) — ~1700 input tokens vs ~25000 for full images.
 * The has_text flag automatically routes to OCR when true.
 */
final class ImageEnrichmentTask extends AbstractVisionTask
{
    public function __construct(
        // Reuse the metadata agent — same vision model, same low-res setting
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'image_enrichment'; }

    protected function responseFormatClass(): ?string { return ImageEnrichmentResult::class; }
}
