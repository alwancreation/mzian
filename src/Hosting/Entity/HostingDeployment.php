<?php

declare(strict_types=1);

namespace App\Hosting\Entity;

use App\Project\Entity\Project;
use App\Shared\Entity\TimestampableTrait;
use App\Shared\Enum\ResourceStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Website space created on a hosting account (document root, PHP version, database, SSL).
 */
#[ORM\Entity]
#[ORM\Table(name: 'hosting_deployment')]
#[ORM\HasLifecycleCallbacks]
class HostingDeployment
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HostingAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private HostingAccount $hostingAccount;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 120)]
    private string $externalId;

    #[ORM\Column(length: 255)]
    private string $documentRoot;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $phpVersion;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $databaseName;

    #[ORM\Column]
    private bool $sslEnabled;

    #[ORM\Column(length: 20, enumType: ResourceStatus::class)]
    private ResourceStatus $status = ResourceStatus::Active;

    #[ORM\Column(length: 160, unique: true)]
    private string $idempotencyKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    public function __construct(HostingAccount $hostingAccount, string $externalId, string $documentRoot, ?string $phpVersion, ?string $databaseName, bool $sslEnabled, string $idempotencyKey)
    {
        $this->hostingAccount = $hostingAccount;
        $this->project = $hostingAccount->getProject();
        $this->externalId = $externalId;
        $this->documentRoot = $documentRoot;
        $this->phpVersion = $phpVersion;
        $this->databaseName = $databaseName;
        $this->sslEnabled = $sslEnabled;
        $this->idempotencyKey = $idempotencyKey;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHostingAccount(): HostingAccount
    {
        return $this->hostingAccount;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getDocumentRoot(): string
    {
        return $this->documentRoot;
    }

    public function getPhpVersion(): ?string
    {
        return $this->phpVersion;
    }

    public function getDatabaseName(): ?string
    {
        return $this->databaseName;
    }

    public function isSslEnabled(): bool
    {
        return $this->sslEnabled;
    }

    public function getStatus(): ResourceStatus
    {
        return $this->status;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
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
