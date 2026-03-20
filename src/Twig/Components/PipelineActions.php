<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Twig\Components;

use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders available AI pipelines and individual task buttons for any entity
 * that carries aiQueue/aiCompleted/aiLocked (HasAiVisionTrait).
 *
 * Usage in image/show.html.twig:
 *   <twig:PipelineActions
 *     :entity="image"
 *     taskRoute="image_run_task"
 *     pipelineRoute="image_enqueue_pipeline"
 *     :routeParams="image.rp"
 *   />
 *
 * The taskRoute must accept {taskName} and the pipelineRoute {pipelineName}
 * as POST parameters.  Both return JSON {ok, marking}.
 *
 * Eventually these routes will live in a bundle controller registered via recipe.
 */
#[AsTwigComponent('PipelineActions', template: '@SurvosAiPipeline/components/PipelineActions.html.twig')]
final class PipelineActions
{
    /** The entity being acted on — must have aiQueue, aiCompleted, aiLocked */
    public object $entity;

    /** Route name for running a single task: POST {taskRoute}/{taskName} */
    public string $taskRoute = '';

    /** Route name for running a pipeline: POST {pipelineRoute}/{pipelineName} */
    public string $pipelineRoute = '';

    /** Route params to merge (e.g. ['imageId' => '...']) */
    public array $routeParams = [];

    public function __construct(
        private readonly AiTaskRegistry $registry,
    ) {}

    /**
     * Named pipelines available for quick dispatch.
     * @return array<string, array{label: string, tasks: string[], description: string}>
     */
    public function pipelines(): array
    {
        return [
            'quick' => [
                'label'       => 'Quick Enrich',
                'tasks'       => ['enrich_from_thumbnail'],
                'description' => 'Title, description, keywords, places, date from thumbnail (~$0.0004)',
                'icon'        => 'mdi:lightning-bolt',
            ],
            'handwriting' => [
                'label'       => 'Handwriting',
                'tasks'       => ['transcribe_handwriting', 'context_description'],
                'description' => 'Transcribe handwritten text then contextualize',
                'icon'        => 'mdi:pencil-outline',
            ],
            'census' => [
                'label'       => 'Census / Tabular',
                'tasks'       => ['extract_census'],
                'description' => 'Extract structured table data — census records, ledgers, registries',
                'icon'        => 'mdi:table',
            ],
            'foreign' => [
                'label'       => 'Foreign Language',
                'tasks'       => ['ocr_mistral', 'translate', 'summarize'],
                'description' => 'OCR → translate → summarize (Soviet Life, non-English docs)',
                'icon'        => 'mdi:translate',
            ],
        ];
    }

    /**
     * All registered tasks with their metadata, grouped by type.
     * @return array<string, array{label: string, group: string, done: bool, failed: bool, result: ?array}>
     */
    public function tasks(): array
    {
        $completed = [];
        foreach ($this->entity->aiCompleted ?? [] as $entry) {
            if (isset($entry['task'])) {
                $completed[$entry['task']] = $entry;
            }
        }
        // enrich_from_thumbnail lives in defaults, not aiCompleted
        if (!empty($this->entity->defaults['enrich_from_thumbnail'])) {
            $completed['enrich_from_thumbnail'] = [
                'task'   => 'enrich_from_thumbnail',
                'result' => $this->entity->defaults['enrich_from_thumbnail'],
            ];
        }

        $out = [];
        foreach ($this->registry->getTaskMap() as $taskName => $serviceId) {
            $entry  = $completed[$taskName] ?? null;
            $result = $entry['result'] ?? null;
            $out[$taskName] = [
                'label'  => $this->taskLabel($taskName),
                'group'  => $this->taskGroup($taskName),
                'done'   => $entry !== null && empty($result['failed']),
                'failed' => !empty($result['failed']),
                'result' => $result,
            ];
        }

        // Sort: done tasks first within each group
        uasort($out, static fn($a, $b) => $b['done'] <=> $a['done']);

        return $out;
    }

    public function isLocked(): bool
    {
        return (bool) ($this->entity->aiLocked ?? false);
    }

    public function queuedTasks(): array
    {
        return $this->entity->aiQueue ?? [];
    }

    private function taskGroup(string $task): string
    {
        return match(true) {
            in_array($task, ['ocr', 'ocr_mistral', 'transcribe_handwriting', 'annotate_handwriting', 'layout'], true) => 'ocr',
            default => 'image',
        };
    }

    private function taskLabel(string $task): string
    {
        return match($task) {
            'enrich_from_thumbnail'  => 'Quick Enrich',
            'basic_description'      => 'Description',
            'context_description'    => 'Context Description',
            'generate_title'         => 'Generate Title',
            'keywords'               => 'Keywords',
            'people_and_places'      => 'People & Places',
            'extract_metadata'       => 'Extract Metadata',
            'classify'               => 'Classify',
            'summarize'              => 'Summarize',
            'translate'              => 'Translate',
            'ocr'                    => 'OCR',
            'ocr_mistral'            => 'OCR (Mistral)',
            'transcribe_handwriting' => 'Handwriting Transcription',
            'annotate_handwriting'   => 'Handwriting Annotation',
            'layout'                 => 'Layout Analysis',
            default                  => ucwords(str_replace('_', ' ', $task)),
        };
    }
}
