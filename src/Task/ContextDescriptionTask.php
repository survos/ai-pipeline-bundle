<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\DescriptionResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContextDescriptionTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.description')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'context_description'; }

    protected function promptContext(array $inputs, array $priorResults, array $context = []): array
    {
        return array_merge(parent::promptContext($inputs, $priorResults, $context), [
            'organisations'      => $priorResults['extract_metadata']['organisations'] ?? [],
            'collection_context' => $context['collection_context'] ?? null,
        ]);
    }

    protected function responseFormatClass(): ?string { return DescriptionResult::class; }
}
