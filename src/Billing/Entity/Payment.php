<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\PaymentStatus;
use App\Billing\Repository\PaymentRepository;
use App\Order\Entity\Order;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_provider_ref', fields: ['provider', 'providerReference'])]
#[ORM\Index(name: 'idx_payment_status', fields: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Payment
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    /** Payment driver code: mock, manual, stripe... */
    #[ORM\Column(length: 40)]
    private string $provider;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::Pending;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $checkoutUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failureReason = null;

    /** @var array<string, mixed> non-sensitive provider data (never card data) */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(Order $order, string $provider, string $idempotencyKey)
    {
        $this->order = $order;
        $this->provider = $provider;
        $this->idempotencyKey = $idempotencyKey;
        $this->amount = $order->getTotal();
        $this->currency = $order->getCurrency();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
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

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(?string $method): void
    {
        $this->method = $method;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function setCheckoutUrl(?string $checkoutUrl): void
    {
        $this->checkoutUrl = $checkoutUrl;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function mergeMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function requireAction(): void
    {
        if (!$this->status->isFinal()) {
            $this->status = PaymentStatus::RequiresAction;
        }
    }

    public function markSucceeded(): void
    {
        $this->status = PaymentStatus::Succeeded;
        $this->failureReason = null;
        $this->paidAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        $this->status = PaymentStatus::Failed;
        $this->failureReason = mb_substr($reason, 0, 500);
    }

    public function markRefunded(): void
    {
        $this->status = PaymentStatus::Refunded;
    }

    public function cancel(): void
    {
        if (!$this->status->isFinal()) {
            $this->status = PaymentStatus::Cancelled;
        }
    }
}
