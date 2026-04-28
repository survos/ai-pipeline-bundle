<?php
declare(strict_types=1);

namespace Survos\AiPipelineBundle\Task;

trait TaskNameTrait
{
    public function getTask(): string
    {
        return static::TASK;
    }
}
