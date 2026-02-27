<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle;

use Survos\AiPipelineBundle\Command\AiPipelineRunCommand;
use Survos\AiPipelineBundle\Command\AiPipelineTasksCommand;
use Survos\AiPipelineBundle\DependencyInjection\Compiler\AiTaskRegistryPass;
use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Survos\AiPipelineBundle\Task\AiPipelineRunner;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class SurvosAiPipelineBundle extends AbstractBundle
{
    // ── Configuration schema ──────────────────────────────────────────────────

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('store_dir')
                    ->defaultValue('%kernel.project_dir%/var/ai-results')
                    ->info('Directory for JsonFileResultStore output.')
                ->end()
            ->end();
    }

    // ── Service registration ──────────────────────────────────────────────────

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('survos_ai_pipeline.store_dir', $config['store_dir'])
            ->set('survos_ai_pipeline.task_map',  []); // placeholder; overwritten by compiler pass

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Registry — map injected by AiTaskRegistryPass at compile time
        $services->set(AiTaskRegistry::class)
            ->public()
            ->arg('$container', service('service_container'))
            ->arg('$taskMap', '%survos_ai_pipeline.task_map%');

        // Runner — depends on registry
        $services->set(AiPipelineRunner::class)
            ->public();

        // Commands
        $services->set(AiPipelineTasksCommand::class)
            ->tag('console.command');

        $services->set(AiPipelineRunCommand::class)
            ->arg('$defaultStoreDir', '%survos_ai_pipeline.store_dir%')
            ->tag('console.command');
    }

    // ── Compiler pass + autoconfiguration ────────────────────────────────────

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Auto-tag every service implementing AiTaskInterface
        $container->registerForAutoconfiguration(AiTaskInterface::class)
            ->addTag('ai_pipeline.task');

        // Compiler pass: scan tagged services, build task_map parameter at compile time
        $container->addCompilerPass(new AiTaskRegistryPass());
    }
}
