<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\WebhookEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Every authenticated webhook received. The (provider, eventId) unique key makes
 * webhook processing idempotent: a replayed event is detected and ignored.
 */
#[ORM\Entity(repositoryClass: WebhookEventRepository::class)]
#[ORM\Table(name: 'webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_webhook_provider_event', fields: ['provider', 'eventId'])]
class WebhookEvent
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $channel;

    #[ORM\Column(length: 40)]
    private string $provider;

    #[ORM\Column(length: 191)]
    private string $eventId;

    #[ORM\Column(length: 100)]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RECEIVED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $channel, string $provider, string $eventId, string $type, array $payload)
    {
        $this->channel = $channel;
        $this->provider = $provider;
        $this->eventId = $eventId;
        $this->type = $type;
        $this->payload = $payload;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function markProcessed(string $status = self::STATUS_PROCESSED): void
    {
        $this->status = $status;
        $this->error = null;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = mb_substr($error, 0, 2000);
        $this->processedAt = new \DateTimeImmutable();
    }
}
