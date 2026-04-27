<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Message;

final readonly class RunAiTaskMessage
{
    public function __construct(
        public string $taskRunId,
        /** @var array<string,mixed> Resolved inputs (image URL, text, etc.) */
        public array $inputs = [],
        /** @var array<string,mixed> Snapshot of prior results at dispatch time */
        public array $priorResults = [],
        /** @var array<string,mixed> Pipeline context (tenant, ocr_text, scan_mode, etc.) */
        public array $context = [],
    ) {}
}
