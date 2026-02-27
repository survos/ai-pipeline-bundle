<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\TitleResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GenerateTitleTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'generate_title'; }

    protected function responseFormatClass(): ?string { return TitleResult::class; }
}
