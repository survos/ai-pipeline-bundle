<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\ClassifyResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ClassifyTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.classify')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'classify'; }

    protected function responseFormatClass(): ?string { return ClassifyResult::class; }
}
