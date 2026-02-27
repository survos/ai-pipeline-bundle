<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only registry of registered AI pipeline task handlers.
 *
 * The task-name → service-id map is built at compile time by AiTaskRegistryPass
 * and injected as the $taskMap constructor argument.  Actual service instances
 * are resolved lazily from the container only when get() is called.
 *
 * Usage:
 *   $registry->has('ocr')            // bool — compiled check
 *   $registry->get('ocr')            // ?AiTaskInterface
 *   $registry->all()                 // array<name, AiTaskInterface>
 *   $registry->getTaskMap()          // array<name, serviceId> — no instantiation
 */
final class AiTaskRegistry
{
    /** @var array<string, AiTaskInterface> resolved service instances */
    private array $resolved = [];

    /**
     * @param array<string,string> $taskMap  task name => service id (compiled in)
     */
    public function __construct(
        private readonly ContainerInterface $container,
        #[Autowire('%survos_ai_pipeline.task_map%')]
        private readonly array $taskMap = [],
    ) {
    }

    public function has(string $taskName): bool
    {
        return isset($this->taskMap[$taskName]);
    }

    public function get(string $taskName): ?AiTaskInterface
    {
        if (!isset($this->taskMap[$taskName])) {
            return null;
        }
        return $this->resolved[$taskName] ??= $this->container->get($this->taskMap[$taskName]);
    }

    /**
     * All registered task handlers, keyed by task name.
     *
     * @return array<string, AiTaskInterface>
     */
    public function all(): array
    {
        foreach ($this->taskMap as $name => $serviceId) {
            $this->resolved[$name] ??= $this->container->get($serviceId);
        }
        return $this->resolved;
    }

    /**
     * Compiled task map without resolving services — for list commands and debugging.
     *
     * @return array<string, string>  task name => service id
     */
    public function getTaskMap(): array
    {
        return $this->taskMap;
    }

    /**
     * getMeta() for all registered tasks — resolves services lazily.
     *
     * @return array<string, array>  task name => getMeta() result
     */
    public function getMeta(): array
    {
        $out = [];
        foreach ($this->taskMap as $name => $serviceId) {
            $handler    = $this->resolved[$name] ??= $this->container->get($serviceId);
            $out[$name] = $handler->getMeta();
        }
        return $out;
    }
}
