<?php

declare(strict_types=1);

namespace App\Project\Entity;

use App\Project\Enum\CostCategory;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money actually committed for a project (hosting bought, domain registered, AI tokens...).
 * The unique idempotency key guarantees a retried operation is never charged twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_cost_entry')]
#[ORM\Index(name: 'idx_cost_category', fields: ['category'])]
class ProjectCostEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'costEntries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 20, enumType: CostCategory::class)]
    private CostCategory $category;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference;

    #[ORM\Column(length: 160, unique: true, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column]
    private bool $simulated;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, CostCategory $category, int $amount, string $currency, string $description, ?string $reference = null, ?string $idempotencyKey = null, bool $simulated = false)
    {
        $this->project = $project;
        $this->category = $category;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->description = mb_substr($description, 0, 255);
        $this->reference = $reference;
        $this->idempotencyKey = $idempotencyKey;
        $this->simulated = $simulated;
        $this->createdAt = new \DateTimeImmutable();
        $project->addCostEntry($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getCategory(): CostCategory
    {
        return $this->category;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
