<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\Notification\Enum\NotificationType;
use App\Notification\Repository\NotificationRepository;
use App\Project\Entity\Project;
use App\Security\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A notification sent to a user (e-mail + in-app feed).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_status', fields: ['status'])]
class Notification
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $recipient;

    #[ORM\Column(length: 180)]
    private string $recipientEmail;

    #[ORM\Column(length: 40, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(length: 20)]
    private string $channel;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $context;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(?User $recipient, string $recipientEmail, NotificationType $type, string $channel, string $subject, string $body, array $context = [], ?Project $project = null)
    {
        $this->recipient = $recipient;
        $this->recipientEmail = $recipientEmail;
        $this->type = $type;
        $this->channel = $channel;
        $this->subject = mb_substr($subject, 0, 255);
        $this->body = $body;
        $this->context = $context;
        $this->project = $project;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function markSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->error = null;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = mb_substr($error, 0, 2000);
    }

    public function markRead(): void
    {
        $this->readAt ??= new \DateTimeImmutable();
    }
}
