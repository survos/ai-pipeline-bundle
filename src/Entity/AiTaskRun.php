<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use Survos\FieldBundle\Attribute\EntityMeta;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\AiPipelineBundle\Enum\AiTaskStatus;
use Symfony\Component\Uid\Ulid;

#[EntityMeta(icon: 'mdi:robot-industrial-outline', group: 'AI')]
#[ApiResource(
    operations: [new GetCollection(), new Get()],
    security: "is_granted('ROLE_ADMIN')",
    paginationItemsPerPage: 30,
    order: ['queuedAt' => 'DESC'],
)]
#[ORM\Entity]
#[ORM\Table(name: 'ai_task_run')]
#[ORM\Index(columns: ['subject_type', 'subject_id'], name: 'ai_task_run_subject_idx')]
#[ORM\Index(columns: ['status'], name: 'ai_task_run_status_idx')]
#[ORM\Index(columns: ['subject_type', 'subject_id', 'task_name'], name: 'ai_task_run_lookup_idx')]
class AiTaskRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.ulid_generator')]
    #[ApiProperty('ULID primary key', identifier: true, example: '01J8KZ3QX4Y5Z6W7V8U9T0RS12')]
    public readonly Ulid $id;

    #[ORM\Column(length: 64)]
    #[ApiProperty('FQCN or short name of the subject entity', example: 'App\Entity\Image')]
    public string $subjectType;

    #[ORM\Column(length: 26)]
    #[ApiProperty('ULID of the subject entity', example: '01J8KZ3QX4Y5Z6W7V8U9T0RS12')]
    public string $subjectId;

    #[ORM\Column(length: 64)]
    #[ApiProperty('Registered task name', example: 'enrich_from_thumbnail')]
    public string $taskName;

    #[ORM\Column(enumType: AiTaskStatus::class)]
    #[ApiProperty('Current execution status', example: 'done')]
    public AiTaskStatus $status = AiTaskStatus::Pending;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[ApiProperty('Structured result payload', example: ['title' => 'Letter from 1923', 'has_text' => true])]
    public ?array $result = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty('Error or skip reason')]
    public ?string $error = null;

    #[ORM\Column]
    #[ApiProperty('When the task was queued', example: '2025-04-24T12:00:00Z')]
    public \DateTimeImmutable $queuedAt;

    #[ORM\Column(nullable: true)]
    #[ApiProperty('When execution started')]
    public ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty('When execution finished (done, failed, or skipped)')]
    public ?\DateTimeImmutable $completedAt = null;

    public function __construct(string $subjectType, string $subjectId, string $taskName)
    {
        $this->subjectType = $subjectType;
        $this->subjectId   = $subjectId;
        $this->taskName    = $taskName;
        $this->queuedAt    = new \DateTimeImmutable();
    }

    public function durationMs(): ?int
    {
        if ($this->startedAt === null || $this->completedAt === null) {
            return null;
        }
        return (int) round(($this->completedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000
            + ($this->completedAt->format('u') - $this->startedAt->format('u')) / 1000);
    }

    public function markRunning(): void
    {
        $this->status    = AiTaskStatus::Running;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function markDone(array $result): void
    {
        $this->status      = AiTaskStatus::Done;
        $this->result      = $result;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status      = AiTaskStatus::Failed;
        $this->error       = $error;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markSkipped(string $reason): void
    {
        $this->status      = AiTaskStatus::Skipped;
        $this->error       = $reason;
        $this->completedAt = new \DateTimeImmutable();
    }
}
