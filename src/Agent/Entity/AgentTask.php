<?php

declare(strict_types=1);

namespace App\Agent\Entity;

use App\Agent\Enum\AgentTaskStatus;
use App\Agent\Repository\AgentTaskRepository;
use App\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Granular, idempotent operation performed by an agent (e.g. "hosting.create_account").
 * A succeeded operation is never executed twice for the same idempotency key.
 */
#[ORM\Entity(repositoryClass: AgentTaskRepository::class)]
#[ORM\Table(name: 'agent_task')]
class AgentTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AgentRun::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AgentRun $run;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 80)]
    private string $operation;

    #[ORM\Column(length: 191, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(length: 12, enumType: AgentTaskStatus::class)]
    private AgentTaskStatus $status = AgentTaskStatus::Running;

    #[ORM\Column]
    private int $attempts = 1;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $input;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $output = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(AgentRun $run, Project $project, string $operation, string $idempotencyKey, array $input = [])
    {
        $this->run = $run;
        $this->project = $project;
        $this->operation = $operation;
        $this->idempotencyKey = $idempotencyKey;
        $this->input = $input;
        $this->startedAt = new \DateTimeImmutable();
        $run->addTask($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): AgentRun
    {
        return $this->run;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getStatus(): AgentTaskStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /** @return array<string, mixed> */
    public function getInput(): array
    {
        return $this->input;
    }

    /** @return array<string, mixed> */
    public function getOutput(): array
    {
        return $this->output;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** A previous attempt failed: run it again inside a new agent run. */
    public function restart(AgentRun $run): void
    {
        $this->run = $run;
        ++$this->attempts;
        $this->status = AgentTaskStatus::Running;
        $this->error = null;
        $this->startedAt = new \DateTimeImmutable();
        $run->addTask($this);
    }

    /**
     * @param array<string, mixed> $output
     */
    public function succeed(array $output): void
    {
        $this->status = AgentTaskStatus::Succeeded;
        $this->output = $output;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = AgentTaskStatus::Failed;
        $this->error = mb_substr($error, 0, 4000);
        $this->finishedAt = new \DateTimeImmutable();
    }
}
