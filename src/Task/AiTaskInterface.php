<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

/**
 * Contract every AI pipeline task must satisfy.
 *
 * A task receives a bag of inputs (keyed by name) plus caller-supplied context,
 * runs one operation (OCR, classify, summarise, translate…), and returns a
 * JSON-serializable result array stored in the pipeline's completed results.
 *
 * Inputs can be anything: an image URL, extracted text, child-entity results,
 * scraped HTML, song lyrics, etc.  The task declares what it needs via
 * supports() — if the required inputs are absent, the runner skips it.
 *
 * Register task classes as services — they are auto-tagged via autoconfiguration
 * in SurvosAiPipelineBundle::loadExtension().
 */
interface AiTaskInterface
{
    /**
     * Unique string identifier for this task (e.g. 'ocr', 'summarize', 'translate').
     * Used as the key in the results map.  Must be stable across deploys.
     */
    public function getTask(): string;

    /**
     * Run the task.
     *
     * @param array $inputs        Named inputs available to this task.
     *                             Common keys: 'image_url', 'text', 'html', 'child_results', etc.
     * @param array $priorResults  Results of tasks that ran before this one, keyed by task name.
     * @param array $context       Arbitrary caller-supplied metadata (title, dates, collection…)
     *
     * @return array  JSON-serializable result stored verbatim in completed results.
     * @throws \Throwable on unrecoverable failure.
     */
    public function run(array $inputs, array $priorResults = [], array $context = []): array;

    /**
     * Whether this task can run given the available inputs and context.
     * Return false to skip gracefully (e.g. no image URL for an OCR task,
     * no prior OCR text for a translate task).
     */
    public function supports(array $inputs, array $context = []): bool;

    /**
     * Human-readable metadata for the task registry / list command.
     *
     * Suggested keys: agent, model, platform, description
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array;
}
