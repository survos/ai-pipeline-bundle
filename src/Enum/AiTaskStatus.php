<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Enum;

enum AiTaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done    = 'done';
    case Failed  = 'failed';
    case Skipped = 'skipped';
}
