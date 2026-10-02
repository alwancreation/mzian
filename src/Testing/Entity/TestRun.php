<?php

declare(strict_types=1);

namespace App\Testing\Entity;

use App\Project\Entity\Project;
use App\Testing\Enum\TestRunType;
use App\Testing\Enum\TestSeverity;
use App\Testing\Enum\TestStatus;
use App\Testing\Repository\TestRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run of technical checks. The status and score are *computed from the executed
 * results* — never declared by an AI.
 */
#[ORM\Entity(repositoryClass: TestRunRepository::class)]
#[ORM\Table(name: 'test_run')]
class TestRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 12, enumType: TestRunType::class)]
    private TestRunType $type;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $target;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, TestResult> */
    #[ORM\OneToMany(targetEntity: TestResult::class, mappedBy: 'testRun', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $results;

    public function __construct(Project $project, TestRunType $type, ?string $target = null)
    {
        $this->project = $project;
        $this->type = $type;
        $this->target = $target;
        $this->startedAt = new \DateTimeImmutable();
        $this->results = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getType(): TestRunType
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPassed(): bool
    {
        return self::STATUS_PASSED === $this->status;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return Collection<int, TestResult> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function addResult(TestResult $result): void
    {
        $this->results->add($result);
    }

    /** @return list<TestResult> */
    public function getCriticalFailures(): array
    {
        return array_values($this->results->filter(
            static fn (TestResult $r) => TestStatus::Failed === $r->getStatus() && TestSeverity::Critical === $r->getSeverity()
        )->toArray());
    }

    /** @return list<TestResult> */
    public function getWarnings(): array
    {
        return array_values($this->results->filter(
            static fn (TestResult $r) => TestStatus::Warning === $r->getStatus()
                || (TestStatus::Failed === $r->getStatus() && TestSeverity::Critical !== $r->getSeverity())
        )->toArray());
    }

    public function countByStatus(TestStatus $status): int
    {
        return $this->results->filter(static fn (TestResult $r) => $r->getStatus() === $status)->count();
    }

    /**
     * Computes status and score from the executed checks:
     * - failed if at least one critical check failed;
     * - score = weighted share of passed checks (skipped checks are excluded).
     */
    public function finish(): void
    {
        $weights = [TestSeverity::Critical->value => 3, TestSeverity::Major->value => 2, TestSeverity::Minor->value => 1];
        $total = 0;
        $earned = 0.0;
        foreach ($this->results as $result) {
            if (TestStatus::Skipped === $result->getStatus()) {
                continue;
            }
            $weight = $weights[$result->getSeverity()->value];
            $total += $weight;
            $earned += match ($result->getStatus()) {
                TestStatus::Passed => $weight,
                TestStatus::Warning => $weight / 2,
                default => 0,
            };
        }
        $this->score = $total > 0 ? (int) round($earned * 100 / $total) : 0;
        $this->status = ([] === $this->getCriticalFailures() && $total > 0) ? self::STATUS_PASSED : self::STATUS_FAILED;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /**
     * QA report format: {"status": "passed", "score": 95, "critical_errors": [], "warnings": []}.
     *
     * @return array{status: string, score: int|null, critical_errors: list<string>, warnings: list<string>, checks: int, skipped: int}
     */
    public function toReport(): array
    {
        return [
            'status' => $this->status,
            'score' => $this->score,
            'critical_errors' => array_map(static fn (TestResult $r) => $r->getName().': '.$r->getMessage(), $this->getCriticalFailures()),
            'warnings' => array_map(static fn (TestResult $r) => $r->getName().': '.$r->getMessage(), $this->getWarnings()),
            'checks' => $this->results->count(),
            'skipped' => $this->countByStatus(TestStatus::Skipped),
        ];
    }
}
