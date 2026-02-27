<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Survos\AiPipelineBundle\Result\OcrResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TranscribeHandwritingTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.mistral_vision')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'transcribe_handwriting'; }

    public function supports(array $inputs, array $context = []): bool
    {
        return ($inputs['image_url'] ?? '') !== '';
    }

    protected function responseFormatClass(): ?string { return OcrResult::class; }
}
