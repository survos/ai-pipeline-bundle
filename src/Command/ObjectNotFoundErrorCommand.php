<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Command;

use Survos\AiPipelineBundle\Result\DescriptionResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:object-not-found-error', 'Minimal Symfony AI ImageUrl + response_format reproducer')]
final class ObjectNotFoundErrorCommand
{
    public function __construct(
        #[Autowire(service: 'ai.agent.description')]
        private readonly AgentInterface $agent,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Public image URL sent as Symfony AI ImageUrl')]
        string $imageUrl = 'https://ssai.fsn1.your-objectstorage.com/marac/005/derivatives/0002.jpg',
    ): int {
        $systemPrompt = 'You are a museum archivist generating visual descriptions. Return structured JSON only.';
        $userPrompt = 'Describe this image in one sentence. Return description, language, and physicalAttributes.';
        $options = ['response_format' => DescriptionResult::class];

        $io->title('Object Not Found Reproducer');
        $io->definitionList(
            ['agent' => 'ai.agent.description'],
            ['image' => $imageUrl],
            ['response_format' => DescriptionResult::class],
        );

        $io->section('Request shape');
        $io->writeln(json_encode([
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => [
                    ['type' => 'input_text', 'text' => $userPrompt],
                    ['type' => 'input_image', 'image_url' => $imageUrl],
                ]],
            ],
            'options' => $options,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        try {
            $result = $this->agent->call(
                new MessageBag(
                    Message::forSystem($systemPrompt),
                    Message::ofUser($userPrompt, new ImageUrl($imageUrl)),
                ),
                $options,
            );
        } catch (\Throwable $e) {
            $io->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            $io->writeln(sprintf('<comment>Thrown at:</comment> %s:%d', $e->getFile(), $e->getLine()));
            $previous = $e->getPrevious();
            while ($previous !== null) {
                $io->writeln(sprintf('<comment>Previous:</comment> %s: %s', $previous::class, $previous->getMessage()));
                $io->writeln(sprintf('<comment>Previous thrown at:</comment> %s:%d', $previous->getFile(), $previous->getLine()));
                $previous = $previous->getPrevious();
            }
            $io->section('Trace');
            foreach (array_slice($e->getTrace(), 0, 12) as $idx => $frame) {
                $io->writeln(sprintf(
                    '#%d %s:%s %s%s%s()',
                    $idx,
                    $frame['file'] ?? '[internal]',
                    $frame['line'] ?? '?',
                    $frame['class'] ?? '',
                    $frame['type'] ?? '',
                    $frame['function'] ?? ''
                ));
            }

            return Command::FAILURE;
        }

        $io->success(sprintf('Agent succeeded: %s', $result::class));
        $content = $result->getContent();
        if ($content instanceof \JsonSerializable) {
            $content = $content->jsonSerialize();
        } elseif (is_object($content)) {
            $content = get_object_vars($content);
        }

        $io->section('Result');
        $io->writeln(is_array($content)
            ? json_encode($content, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
            : (string) $content);

        return Command::SUCCESS;
    }
}
