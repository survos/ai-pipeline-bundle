<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle;

use Survos\CoreBundle\Bundle\AssetMapperBundle;
use Survos\AiPipelineBundle\Command\AiPipelineRunCommand;
use Survos\AiPipelineBundle\Command\AiImageCommand;
use Survos\AiPipelineBundle\Command\AiPipelineTasksCommand;
use Survos\AiPipelineBundle\DependencyInjection\Compiler\AiTaskRegistryPass;
use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Survos\AiPipelineBundle\Task\AiPipelineRunner;
use Survos\AiPipelineBundle\Task\ContextDescriptionTask;
use Survos\AiPipelineBundle\Task\ExtractMetadataTask;
use Survos\AiPipelineBundle\Task\GenerateTitleTask;
use Survos\AiPipelineBundle\Task\LayoutTask;
use Survos\AiPipelineBundle\Task\OcrMistralTask;
use Survos\AiPipelineBundle\Task\OcrTask;
use Survos\AiPipelineBundle\Task\SummarizeTask;
use Survos\AiPipelineBundle\Task\TranscribeHandwritingTask;
use Survos\AiPipelineBundle\Task\AnnotateHandwritingTask;
use Survos\AiPipelineBundle\Task\TranslateTask;
use Survos\AiPipelineBundle\Task\CensusExtractionTask;
use Survos\AiPipelineBundle\Task\EnrichFromThumbnailTask;
use Survos\AiPipelineBundle\Menu\AiPipelineMenuSubscriber;
use Survos\AiPipelineBundle\MessageHandler\RunAiTaskHandler;
use Survos\AiPipelineBundle\Twig\Components\PipelineActions;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class SurvosAiPipelineBundle extends AssetMapperBundle
{
    public const ASSET_PACKAGE = 'ai-pipeline';

    /**
     * All built-in task classes, keyed by their task name.
     * Used to register defaults and to support the disabled_tasks config.
     */
    public const DEFAULT_TASKS = [
        // ENRICH — the one low-res vision call per image.
        'enrich_from_thumbnail'  => EnrichFromThumbnailTask::class,

        // TRANSCRIBE — routed high-res passes (one call per image, picked by
        // text-type flags emitted during enrich).
        'ocr'                    => OcrTask::class,
        OcrMistralTask::TASK            => OcrMistralTask::class,
        TranscribeHandwritingTask::TASK => TranscribeHandwritingTask::class,
        'annotate_handwriting'   => AnnotateHandwritingTask::class,
        ExtractMetadataTask::TASK       => ExtractMetadataTask::class,
        'extract_census'         => CensusExtractionTask::class,
        'layout'                 => LayoutTask::class,

        // ANALYZE — text-only passes (read existing claims, no image tokens).
        'context_description'    => ContextDescriptionTask::class,
        'generate_title'         => GenerateTitleTask::class,
        'summarize'              => SummarizeTask::class,
        'translate'              => TranslateTask::class,
    ];

    // ── Configuration schema ──────────────────────────────────────────────────

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('store_dir')
                    ->defaultValue('%kernel.project_dir%/var/ai-results')
                    ->info('Directory for JsonFileResultStore output.')
                ->end()
                ->arrayNode('disabled_tasks')
                    ->info('Task names to disable. Use this to turn off built-in tasks you do not need.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    // ── Service registration ──────────────────────────────────────────────────

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $disabled = $config['disabled_tasks'];

        $container->parameters()
            ->set('survos_ai_pipeline.store_dir',     $config['store_dir'])
            ->set('survos_ai_pipeline.disabled_tasks', $disabled)
            ->set('survos_ai_pipeline.task_map',      []); // overwritten by compiler pass

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Registry
        $services->set(AiTaskRegistry::class)
            ->public()
            ->arg('$container', service('service_container'))
            ->arg('$taskMap', '%survos_ai_pipeline.task_map%');

        // Runner
        $services->set(AiPipelineRunner::class)
            ->public();

        if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            $services->set(AiPipelineMenuSubscriber::class)
                ->autowire()
                ->autoconfigure();
        }

        // Messenger handler (only when Messenger is available)
        if (class_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $services->set(RunAiTaskHandler::class)
                ->autowire()
                ->autoconfigure()
                ->tag('messenger.message_handler');
        }

        // Commands
        $services->set(AiPipelineTasksCommand::class)
            ->tag('console.command');

        $services->set(AiPipelineRunCommand::class)
            ->arg('$defaultStoreDir', '%survos_ai_pipeline.store_dir%')
            ->tag('console.command');

        $services->set(AiImageCommand::class)
            ->tag('console.command');

        // ── Register built-in tasks ───────────────────────────────────────────
        // Each is registered as a tagged service unless listed in disabled_tasks.
        // Apps can still override individual tasks by defining their own service
        // with the same class name (it will simply replace this registration).
        // Register PipelineActions Twig component when UX Twig Component is available
        if (class_exists(\Symfony\UX\TwigComponent\Attribute\AsTwigComponent::class)) {
            $builder->register(PipelineActions::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        } else {
            throw new \RuntimeException('Cannot find Symfony UX TwigComponent');
        }

        foreach (self::DEFAULT_TASKS as $taskName => $taskClass) {
            if (in_array($taskName, $disabled, true)) {
                continue;
            }

            // Skip task registration only when symfony/ai-agent is not installed at all.
            // We cannot check individual agent service IDs at compile time — symfony/ai
            // registers them via its own extension which runs after ours.
            $agentServiceId = $this->resolveAgentServiceId($taskClass);
            if ($agentServiceId !== null && !interface_exists(\Symfony\AI\Agent\AgentInterface::class)) {
                continue;
            }

            $services->set($taskClass)
                ->autowire()
                ->autoconfigure()
                ->tag('ai_pipeline.task');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Read the #[Autowire(service: '...')] attribute from the task's constructor
     * to find which agent service it needs.
     */
    private function resolveAgentServiceId(string $taskClass): ?string
    {
        try {
            $rc = new \ReflectionClass($taskClass);
            foreach ($rc->getConstructor()?->getParameters() ?? [] as $param) {
                foreach ($param->getAttributes(\Symfony\Component\DependencyInjection\Attribute\Autowire::class) as $attr) {
                    $args = $attr->getArguments();
                    $svc  = $args['service'] ?? $args[0] ?? null;
                    if (is_string($svc) && str_starts_with($svc, 'ai.agent.')) {
                        return $svc;
                    }
                }
            }
        } catch (\Throwable) {}
        return null;
    }

    // ── Compiler pass + autoconfiguration ────────────────────────────────────

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Register template namespace @SurvosAiPipeline
        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', [
                'paths' => [dirname(__DIR__) . '/templates' => 'SurvosAiPipeline'],
            ]);
        }

        if ($container->hasExtension('twig_component')) {
            $container->prependExtensionConfig('twig_component', [
                'anonymous_template_directory' => 'components',
            ]);
        }

        // Register Doctrine entity mapping
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'SurvosAiPipelineBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => __DIR__ . '/Entity',
                            'prefix'    => 'Survos\\AiPipelineBundle\\Entity',
                            'alias'     => 'SurvosAiPipelineBundle',
                        ],
                    ],
                ],
            ]);
        }

        $container->registerForAutoconfiguration(AiTaskInterface::class)
            ->addTag('ai_pipeline.task');

        $container->addCompilerPass(new AiTaskRegistryPass());
    }
}
