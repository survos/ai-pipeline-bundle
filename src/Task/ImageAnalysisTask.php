<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\ImageAnalysisResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment as TwigEnvironment;

final class ImageAnalysisTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.analysis')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string
    {
        return 'image_analysis';
    }

    public function supports(array $inputs, array $context = []): bool
    {
        return ($inputs['image_url'] ?? '') !== '';
    }

    protected function responseFormatClass(): ?string
    {
        return ImageAnalysisResult::class;
    }
}
