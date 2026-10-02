<?php

declare(strict_types=1);

namespace App\Order\Entity;

use App\Billing\Entity\SubscriptionPlan;
use App\Catalog\Entity\Solution;
use App\Hosting\Entity\HostingPlan;
use App\Order\Enum\QuoteStatus;
use App\Order\Repository\QuoteRepository;
use App\Project\Entity\Project;
use App\Requirement\Entity\Requirement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Price proposal computed by the PricingEngine (never by the AI alone).
 * Keeps cost price, selling price and margin for the approval center.
 */
#[ORM\Entity(repositoryClass: QuoteRepository::class)]
#[ORM\Table(name: 'quote')]
#[ORM\Index(name: 'idx_quote_status', fields: ['status'])]
class Quote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $number;

    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: Requirement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Requirement $requirement;

    #[ORM\ManyToOne(targetEntity: Solution::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Solution $solution;

    #[ORM\ManyToOne(targetEntity: HostingPlan::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HostingPlan $hostingPlan = null;

    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SubscriptionPlan $subscriptionPlan = null;

    #[ORM\Column(length: 12, enumType: QuoteStatus::class)]
    private QuoteStatus $status = QuoteStatus::Issued;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** Total cost for Mzian (minor units). */
    #[ORM\Column]
    private int $costPrice;

    /** One-time price paid by the customer (minor units). */
    #[ORM\Column]
    private int $sellingPrice;

    #[ORM\Column]
    private int $margin;

    #[ORM\Column]
    private float $marginPercentage;

    /** Monthly recurring price (maintenance / subscription), minor units. */
    #[ORM\Column]
    private int $recurringMonthly = 0;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $features;

    #[ORM\Column(length: 253, nullable: true)]
    private ?string $domainName = null;

    /** @var array<string, mixed> pricing policy + inputs used, for audit and reproducibility */
    #[ORM\Column(type: Types::JSON)]
    private array $pricingSnapshot = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /** @var Collection<int, QuoteItem> */
    #[ORM\OneToMany(targetEntity: QuoteItem::class, mappedBy: 'quote', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    /**
     * @param list<string> $features
     */
    public function __construct(
        string $number,
        Project $project,
        Requirement $requirement,
        Solution $solution,
        string $currency,
        int $costPrice,
        int $sellingPrice,
        array $features,
        \DateTimeImmutable $validUntil,
    ) {
        $this->number = $number;
        $this->token = bin2hex(random_bytes(16));
        $this->project = $project;
        $this->requirement = $requirement;
        $this->solution = $solution;
        $this->currency = $currency;
        $this->costPrice = $costPrice;
        $this->sellingPrice = $sellingPrice;
        $this->margin = $sellingPrice - $costPrice;
        $this->marginPercentage = $sellingPrice > 0 ? round($this->margin * 100 / $sellingPrice, 2) : 0.0;
        $this->features = $features;
        $this->validUntil = $validUntil;
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getRequirement(): Requirement
    {
        return $this->requirement;
    }

    public function getSolution(): Solution
    {
        return $this->solution;
    }

    public function getHostingPlan(): ?HostingPlan
    {
        return $this->hostingPlan;
    }

    public function setHostingPlan(?HostingPlan $hostingPlan): void
    {
        $this->hostingPlan = $hostingPlan;
    }

    public function getSubscriptionPlan(): ?SubscriptionPlan
    {
        return $this->subscriptionPlan;
    }

    public function setSubscriptionPlan(?SubscriptionPlan $subscriptionPlan): void
    {
        $this->subscriptionPlan = $subscriptionPlan;
    }

    public function getStatus(): QuoteStatus
    {
        return $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCostPrice(): int
    {
        return $this->costPrice;
    }

    public function getSellingPrice(): int
    {
        return $this->sellingPrice;
    }

    public function getMargin(): int
    {
        return $this->margin;
    }

    public function getMarginPercentage(): float
    {
        return $this->marginPercentage;
    }

    public function getRecurringMonthly(): int
    {
        return $this->recurringMonthly;
    }

    public function setRecurringMonthly(int $recurringMonthly): void
    {
        $this->recurringMonthly = $recurringMonthly;
    }

    /** @return list<string> */
    public function getFeatures(): array
    {
        return $this->features;
    }

    public function getDomainName(): ?string
    {
        return $this->domainName;
    }

    public function setDomainName(?string $domainName): void
    {
        $this->domainName = $domainName;
    }

    /** @return array<string, mixed> */
    public function getPricingSnapshot(): array
    {
        return $this->pricingSnapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function setPricingSnapshot(array $snapshot): void
    {
        $this->pricingSnapshot = $snapshot;
    }

    public function getValidUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return QuoteStatus::Expired === $this->status || (QuoteStatus::Issued === $this->status && $this->validUntil < $now);
    }

    public function isAcceptable(): bool
    {
        return QuoteStatus::Issued === $this->status && !$this->isExpired();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function accept(): void
    {
        if (!$this->isAcceptable()) {
            throw new \LogicException(\sprintf('Quote %s cannot be accepted (status %s).', $this->number, $this->status->value));
        }
        $this->status = QuoteStatus::Accepted;
        $this->acceptedAt = new \DateTimeImmutable();
    }

    public function supersede(): void
    {
        if (QuoteStatus::Issued === $this->status) {
            $this->status = QuoteStatus::Superseded;
        }
    }

    /** @return Collection<int, QuoteItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return list<QuoteItem> */
    public function getCustomerItems(): array
    {
        return array_values($this->items->filter(static fn (QuoteItem $i) => $i->isCustomerVisible())->toArray());
    }

    public function addItem(QuoteItem $item): void
    {
        $this->items->add($item);
    }
}
