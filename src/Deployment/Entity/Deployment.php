<?php

declare(strict_types=1);

namespace App\Deployment\Entity;

use App\Deployment\Enum\DeploymentStatus;
use App\Deployment\Repository\DeploymentRepository;
use App\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A release of the customer application to an environment.
 * "url" is public; "internalUrl" is how Mzian's workers reach it (QA checks).
 */
#[ORM\Entity(repositoryClass: DeploymentRepository::class)]
#[ORM\Table(name: 'deployment')]
#[ORM\Index(name: 'idx_deployment_status', fields: ['status'])]
class Deployment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 60)]
    private string $provider;

    #[ORM\Column(length: 20)]
    private string $environment;

    #[ORM\Column(length: 40)]
    private string $version;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $commitSha;

    #[ORM\Column(length: 20, enumType: DeploymentStatus::class)]
    private DeploymentStatus $status = DeploymentStatus::Pending;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $internalUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 160, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column]
    private bool $simulated;

    /** @var list<array{at: string, message: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $logs = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Project $project, string $provider, string $environment, string $version, ?string $commitSha, string $idempotencyKey, bool $simulated)
    {
        $this->project = $project;
        $this->provider = $provider;
        $this->environment = $environment;
        $this->version = $version;
        $this->commitSha = $commitSha;
        $this->idempotencyKey = $idempotencyKey;
        $this->simulated = $simulated;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getCommitSha(): ?string
    {
        return $this->commitSha;
    }

    public function getStatus(): DeploymentStatus
    {
        return $this->status;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getInternalUrl(): ?string
    {
        return $this->internalUrl;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    /** @return list<array{at: string, message: string}> */
    public function getLogs(): array
    {
        return $this->logs;
    }

    public function log(string $message): void
    {
        $this->logs[] = ['at' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'message' => mb_substr($message, 0, 500)];
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function start(): void
    {
        $this->status = DeploymentStatus::Running;
    }

    public function succeed(string $url, string $internalUrl, ?string $externalId): void
    {
        $this->status = DeploymentStatus::Succeeded;
        $this->url = $url;
        $this->internalUrl = $internalUrl;
        $this->externalId = $externalId;
        $this->error = null;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = DeploymentStatus::Failed;
        $this->error = mb_substr($error, 0, 4000);
        $this->finishedAt = new \DateTimeImmutable();
    }
}
