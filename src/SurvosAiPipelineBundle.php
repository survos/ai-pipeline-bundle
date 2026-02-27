<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle;

use Survos\AiPipelineBundle\Command\AiPipelineRunCommand;
use Survos\AiPipelineBundle\Command\AiPipelineTasksCommand;
use Survos\AiPipelineBundle\DependencyInjection\Compiler\AiTaskRegistryPass;
use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Survos\AiPipelineBundle\Task\AiPipelineRunner;
use Survos\AiPipelineBundle\Task\BasicDescriptionTask;
use Survos\AiPipelineBundle\Task\ClassifyTask;
use Survos\AiPipelineBundle\Task\ContextDescriptionTask;
use Survos\AiPipelineBundle\Task\ExtractMetadataTask;
use Survos\AiPipelineBundle\Task\GenerateTitleTask;
use Survos\AiPipelineBundle\Task\KeywordsTask;
use Survos\AiPipelineBundle\Task\LayoutTask;
use Survos\AiPipelineBundle\Task\OcrMistralTask;
use Survos\AiPipelineBundle\Task\OcrTask;
use Survos\AiPipelineBundle\Task\PeopleAndPlacesTask;
use Survos\AiPipelineBundle\Task\SummarizeTask;
use Survos\AiPipelineBundle\Task\TranscribeHandwritingTask;
use Survos\AiPipelineBundle\Task\TranslateTask;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class SurvosAiPipelineBundle extends AbstractBundle
{
    /**
     * All built-in task classes, keyed by their task name.
     * Used to register defaults and to support the disabled_tasks config.
     */
    public const DEFAULT_TASKS = [
        'basic_description'      => BasicDescriptionTask::class,
        'classify'               => ClassifyTask::class,
        'context_description'    => ContextDescriptionTask::class,
        'extract_metadata'       => ExtractMetadataTask::class,
        'generate_title'         => GenerateTitleTask::class,
        'keywords'               => KeywordsTask::class,
        'layout'                 => LayoutTask::class,
        'ocr'                    => OcrTask::class,
        'ocr_mistral'            => OcrMistralTask::class,
        'people_and_places'      => PeopleAndPlacesTask::class,
        'summarize'              => SummarizeTask::class,
        'transcribe_handwriting' => TranscribeHandwritingTask::class,
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

        // Commands
        $services->set(AiPipelineTasksCommand::class)
            ->tag('console.command');

        $services->set(AiPipelineRunCommand::class)
            ->arg('$defaultStoreDir', '%survos_ai_pipeline.store_dir%')
            ->tag('console.command');

        // ── Register built-in tasks ───────────────────────────────────────────
        // Each is registered as a tagged service unless listed in disabled_tasks.
        // Apps can still override individual tasks by defining their own service
        // with the same class name (it will simply replace this registration).
        foreach (self::DEFAULT_TASKS as $taskName => $taskClass) {
            if (in_array($taskName, $disabled, true)) {
                continue;
            }
            $services->set($taskClass)
                ->autowire()
                ->autoconfigure()
                ->tag('ai_pipeline.task');
        }
    }

    // ── Template path ─────────────────────────────────────────────────────────

    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    // ── Compiler pass + autoconfiguration ────────────────────────────────────

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(AiTaskInterface::class)
            ->addTag('ai_pipeline.task');

        $container->addCompilerPass(new AiTaskRegistryPass());
    }
}
