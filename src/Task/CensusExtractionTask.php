<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\CensusExtractionResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CensusExtractionTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.mistral_vision')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'extract_census'; }

    public function supports(array $inputs, array $context = []): bool
    {
        return ($inputs['image_url'] ?? '') !== '';
    }

    // Use raw JSON parsing instead of structured output — the nested array schema
    // (tables[].rows[][]) is not reliably handled by OpenAI's structured output mode.
    // The prompt explicitly requests JSON and the runner parses it from the response.
    protected function responseFormatClass(): ?string { return null; }
}
