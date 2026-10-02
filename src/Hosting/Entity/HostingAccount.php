<?php

declare(strict_types=1);

namespace App\Hosting\Entity;

use App\Hosting\Repository\HostingAccountRepository;
use App\Project\Entity\Project;
use App\Shared\Entity\TimestampableTrait;
use App\Shared\Enum\ResourceStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Hosting account created at a provider for a project. The idempotency key is
 * unique, so a retried provisioning can never create a second account.
 */
#[ORM\Entity(repositoryClass: HostingAccountRepository::class)]
#[ORM\Table(name: 'hosting_account')]
#[ORM\HasLifecycleCallbacks]
class HostingAccount
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 60)]
    private string $provider;

    #[ORM\ManyToOne(targetEntity: HostingPlan::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HostingPlan $plan;

    #[ORM\Column(length: 120)]
    private string $externalId;

    #[ORM\Column(length: 20, enumType: ResourceStatus::class)]
    private ResourceStatus $status = ResourceStatus::Provisioning;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $controlPanelUrl = null;

    #[ORM\Column]
    private int $cost;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 160, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column]
    private bool $simulated;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    public function __construct(Project $project, string $provider, ?HostingPlan $plan, string $externalId, int $cost, string $currency, string $idempotencyKey, bool $simulated)
    {
        $this->project = $project;
        $this->provider = $provider;
        $this->plan = $plan;
        $this->externalId = $externalId;
        $this->cost = $cost;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->simulated = $simulated;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getPlan(): ?HostingPlan
    {
        return $this->plan;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getStatus(): ResourceStatus
    {
        return $this->status;
    }

    public function activate(?string $username, ?string $region, ?string $ipAddress, ?string $controlPanelUrl, ?\DateTimeImmutable $expiresAt): void
    {
        $this->status = ResourceStatus::Active;
        $this->username = $username;
        $this->region = $region;
        $this->ipAddress = $ipAddress;
        $this->controlPanelUrl = $controlPanelUrl;
        $this->expiresAt = $expiresAt;
    }

    public function markFailed(): void
    {
        $this->status = ResourceStatus::Failed;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getControlPanelUrl(): ?string
    {
        return $this->controlPanelUrl;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }
}
