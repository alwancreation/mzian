<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\BillingInterval;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\SubscriptionRepository;
use App\Customer\Entity\Customer;
use App\Project\Entity\Project;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\Index(name: 'idx_subscription_status', fields: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project;

    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SubscriptionPlan $plan;

    #[ORM\Column(length: 12, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::Pending;

    #[ORM\Column]
    private int $price;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'billing_interval', length: 10, enumType: BillingInterval::class)]
    private BillingInterval $interval;

    #[ORM\Column(length: 40)]
    private string $provider;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $currentPeriodStart = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function __construct(Customer $customer, ?Project $project, SubscriptionPlan $plan, string $currency, string $provider)
    {
        $this->customer = $customer;
        $this->project = $project;
        $this->plan = $plan;
        $this->price = $plan->getPrice();
        $this->interval = $plan->getInterval();
        $this->currency = $currency;
        $this->provider = $provider;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getPlan(): SubscriptionPlan
    {
        return $this->plan;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getInterval(): BillingInterval
    {
        return $this->interval;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function setProviderReference(?string $providerReference): void
    {
        $this->providerReference = $providerReference;
    }

    public function getCurrentPeriodStart(): ?\DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function activate(\DateTimeImmutable $start = new \DateTimeImmutable()): void
    {
        $this->status = SubscriptionStatus::Active;
        $this->currentPeriodStart = $start;
        $this->currentPeriodEnd = $start->modify(\sprintf('+%d months', $this->interval->months()));
    }

    public function renew(): void
    {
        $start = $this->currentPeriodEnd ?? new \DateTimeImmutable();
        $this->activate($start);
    }

    public function markPastDue(): void
    {
        $this->status = SubscriptionStatus::PastDue;
    }

    public function cancel(): void
    {
        $this->status = SubscriptionStatus::Cancelled;
        $this->cancelledAt = new \DateTimeImmutable();
    }

    public function isDue(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return SubscriptionStatus::Active === $this->status && null !== $this->currentPeriodEnd && $this->currentPeriodEnd <= $now;
    }
}
