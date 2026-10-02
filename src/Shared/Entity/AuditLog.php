<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Enum\ActorType;
use App\Shared\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only trail of every important action: who, what, when, on which entity,
 * old/new values, IP and metadata. Secrets must never be written here.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_entity', fields: ['entityType', 'entityId'])]
#[ORM\Index(name: 'idx_audit_action', fields: ['action'])]
#[ORM\Index(name: 'idx_audit_created', fields: ['createdAt'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ActorType::class)]
    private ActorType $actorType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(length: 120)]
    private string $actorName;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $entityType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $entityId;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $oldValue;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $newValue;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $message;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     * @param array<string, mixed>      $metadata
     */
    public function __construct(
        ActorType $actorType,
        ?string $actorId,
        string $actorName,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $metadata = [],
        ?string $message = null,
    ) {
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->actorName = mb_substr($actorName, 0, 120);
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->ip = $ip;
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 255) : null;
        $this->metadata = $metadata;
        $this->message = null !== $message ? mb_substr($message, 0, 255) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActorType(): ActorType
    {
        return $this->actorType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getActorName(): string
    {
        return $this->actorName;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    /** @return array<string, mixed>|null */
    public function getOldValue(): ?array
    {
        return $this->oldValue;
    }

    /** @return array<string, mixed>|null */
    public function getNewValue(): ?array
    {
        return $this->newValue;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
