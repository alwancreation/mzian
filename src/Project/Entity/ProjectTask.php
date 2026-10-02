<?php

declare(strict_types=1);

namespace App\Project\Entity;

use App\Project\Enum\PipelineStep;
use App\Project\Enum\ProjectTaskStatus;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pipeline step of a project (hosting, domain, development...). Admin controls
 * (retry, skip, restart) operate on these tasks. Unique per (project, step).
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_task')]
#[ORM\UniqueConstraint(name: 'uniq_project_task_step', fields: ['project', 'step'])]
#[ORM\Index(name: 'idx_project_task_status', fields: ['status'])]
#[ORM\HasLifecycleCallbacks]
class ProjectTask
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 20, enumType: PipelineStep::class)]
    private PipelineStep $step;

    #[ORM\Column(length: 40)]
    private string $agentCode;

    #[ORM\Column(length: 20, enumType: ProjectTaskStatus::class)]
    private ProjectTaskStatus $status = ProjectTaskStatus::Pending;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private int $maxAttempts;

    #[ORM\Column]
    private int $position;

    /** Total spent by this step (minor units). */
    #[ORM\Column]
    private int $cost = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $output = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Project $project, PipelineStep $step, int $maxAttempts = 3)
    {
        $this->project = $project;
        $this->step = $step;
        $this->agentCode = $step->agentCode();
        $this->position = $step->position();
        $this->maxAttempts = max(1, $maxAttempts);
        $project->addTask($this);
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getStep(): PipelineStep
    {
        return $this->step;
    }

    public function getAgentCode(): string
    {
        return $this->agentCode;
    }

    public function getStatus(): ProjectTaskStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function addCost(int $amount): void
    {
        $this->cost += $amount;
    }

    /** @return array<string, mixed> */
    public function getOutput(): array
    {
        return $this->output;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationSeconds(): ?int
    {
        if (null === $this->startedAt) {
            return null;
        }
        $end = $this->finishedAt ?? new \DateTimeImmutable();

        return max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());
    }

    public function start(): void
    {
        ++$this->attempts;
        $this->status = ProjectTaskStatus::Running;
        $this->startedAt ??= new \DateTimeImmutable();
        $this->finishedAt = null;
    }

    /**
     * @param array<string, mixed> $output
     */
    public function succeed(array $output = []): void
    {
        $this->status = ProjectTaskStatus::Succeeded;
        $this->output = $output;
        $this->lastError = null;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = ProjectTaskStatus::Failed;
        $this->lastError = mb_substr($error, 0, 4000);
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function recordRetryableFailure(string $error): void
    {
        $this->status = ProjectTaskStatus::Pending;
        $this->lastError = mb_substr($error, 0, 4000);
    }

    public function waitForAdmin(string $reason): void
    {
        $this->status = ProjectTaskStatus::WaitingAdmin;
        $this->lastError = mb_substr($reason, 0, 4000);
    }

    public function skip(string $reason): void
    {
        $this->status = ProjectTaskStatus::Skipped;
        $this->output = ['skipped_reason' => $reason];
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function cancel(): void
    {
        if (!$this->status->isDone()) {
            $this->status = ProjectTaskStatus::Cancelled;
        }
    }

    /** Reset by an admin: "retry" keeps history, "restart" also re-runs a successful step. */
    public function reset(bool $resetAttempts = true): void
    {
        $this->status = ProjectTaskStatus::Pending;
        if ($resetAttempts) {
            $this->attempts = 0;
        }
        $this->finishedAt = null;
        $this->lastError = null;
    }
}
