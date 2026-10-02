<?php

declare(strict_types=1);

namespace App\Agent\Entity;

use App\Agent\Enum\AgentRunStatus;
use App\Agent\Repository\AgentRunRepository;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectTask;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One execution attempt of an agent, with its (sanitized) log lines, timing,
 * cost and error. This is what admins read to understand a failure.
 */
#[ORM\Entity(repositoryClass: AgentRunRepository::class)]
#[ORM\Table(name: 'agent_run')]
#[ORM\Index(name: 'idx_agent_run_started', fields: ['startedAt'])]
#[ORM\Index(name: 'idx_agent_run_status', fields: ['status'])]
class AgentRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $agentCode;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ProjectTask $projectTask;

    #[ORM\Column]
    private int $attempt;

    #[ORM\Column(length: 20, enumType: AgentRunStatus::class)]
    private AgentRunStatus $status = AgentRunStatus::Running;

    /** @var list<array{at: string, level: string, message: string, context?: array<string, mixed>}> */
    #[ORM\Column(type: Types::JSON)]
    private array $logs = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** Money spent during this run (minor units). */
    #[ORM\Column]
    private int $cost = 0;

    #[ORM\Column]
    private int $tokensInput = 0;

    #[ORM\Column]
    private int $tokensOutput = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /** @var Collection<int, AgentTask> */
    #[ORM\OneToMany(targetEntity: AgentTask::class, mappedBy: 'run', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $tasks;

    public function __construct(string $agentCode, ?Project $project, ?ProjectTask $projectTask, int $attempt = 1)
    {
        $this->agentCode = $agentCode;
        $this->project = $project;
        $this->projectTask = $projectTask;
        $this->attempt = $attempt;
        $this->startedAt = new \DateTimeImmutable();
        $this->tasks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgentCode(): string
    {
        return $this->agentCode;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getProjectTask(): ?ProjectTask
    {
        return $this->projectTask;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getStatus(): AgentRunStatus
    {
        return $this->status;
    }

    /** @return list<array{at: string, level: string, message: string, context?: array<string, mixed>}> */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * @param array<string, mixed> $context must already be sanitized (no secrets)
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $line = ['at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'), 'level' => $level, 'message' => mb_substr($message, 0, 1000)];
        if ([] !== $context) {
            $line['context'] = $context;
        }
        $this->logs[] = $line;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function addCost(int $amount): void
    {
        $this->cost += $amount;
    }

    public function addTokens(int $input, int $output): void
    {
        $this->tokensInput += $input;
        $this->tokensOutput += $output;
    }

    public function getTokensInput(): int
    {
        return $this->tokensInput;
    }

    public function getTokensOutput(): int
    {
        return $this->tokensOutput;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /** @return Collection<int, AgentTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function addTask(AgentTask $task): void
    {
        $this->tasks->add($task);
    }

    public function finish(AgentRunStatus $status, ?string $error = null): void
    {
        $this->status = $status;
        $this->error = null !== $error ? mb_substr($error, 0, 4000) : null;
        $this->finishedAt = new \DateTimeImmutable();
        $this->durationMs = (int) round(((float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000);
    }
}
