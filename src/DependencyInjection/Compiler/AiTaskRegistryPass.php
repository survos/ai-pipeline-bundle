<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\DependencyInjection\Compiler;

use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Builds the task registry at compile time.
 *
 * Scans all services tagged ai_pipeline.task, reads their getTask() return value
 * via reflection (no instantiation required for zero-arg classes) or from an
 * explicit tag attribute, and stores the resulting map as a container parameter.
 *
 * AiTaskRegistry reads that parameter — zero scanning at runtime.
 *
 * Registered by SurvosAiPipelineBundle::build().
 */
final class AiTaskRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $map = [];   // task name => service id

        foreach ($container->findTaggedServiceIds('ai_pipeline.task') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $class      = $definition->getClass() ?? $serviceId;

            if (!class_exists($class)) {
                continue;
            }

            $taskName = $this->resolveTaskName($class, $tags);
            if ($taskName === null) {
                continue;
            }

            // The registry resolves services lazily via ContainerInterface::get(),
            // which requires the service to be public.
            $definition->setPublic(true);

            $map[$taskName] = $serviceId;
        }

        $container->setParameter('survos_ai_pipeline.task_map', $map);

        if ($container->hasDefinition(AiTaskRegistry::class)) {
            $container->getDefinition(AiTaskRegistry::class)
                ->setArgument('$taskMap', $map);
        }
    }

    /**
     * Determine the task name for a handler class.
     *
     * Priority:
     *   1. Explicit `task` attribute on the service tag:  { name: ai_pipeline.task, task: ocr }
     *   2. Reflection: instantiate without constructor args and call getTask()
     *   3. Skip silently if neither works (developer gets a gap in the registry).
     */
    private function resolveTaskName(string $class, array $tags): ?string
    {
        // 1. Explicit tag attribute
        foreach ($tags as $tagAttributes) {
            if (isset($tagAttributes['task'])) {
                return (string) $tagAttributes['task'];
            }
        }

        // 2. newInstanceWithoutConstructor() — safe because getTask() returns a
        //    compile-time constant string and never touches injected properties.
        try {
            $rc       = new \ReflectionClass($class);
            /** @var \Survos\AiPipelineBundle\Task\AiTaskInterface $instance */
            $instance = $rc->newInstanceWithoutConstructor();
            return $instance->getTask();
        } catch (\Throwable) {
        }

        return null;
    }
}
