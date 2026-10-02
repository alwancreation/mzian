<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\DomainStatus;
use App\Domain\Repository\DomainRepository;
use App\Project\Entity\Project;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Domain name registered (or brought by the customer) for a project.
 * The domain name itself is unique: a retry can never register it twice.
 */
#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'domain')]
#[ORM\HasLifecycleCallbacks]
class Domain
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project;

    #[ORM\Column(length: 253, unique: true)]
    private string $name;

    #[ORM\Column(length: 60)]
    private string $provider;

    #[ORM\Column(length: 12, enumType: DomainStatus::class)]
    private DomainStatus $status = DomainStatus::Pending;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column]
    private int $cost = 0;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private bool $autoRenew = true;

    #[ORM\Column]
    private bool $simulated;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $nameservers = [];

    /** @var list<array{type: string, name: string, value: string, ttl?: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $dnsRecords = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(?Project $project, string $name, string $provider, string $currency, bool $simulated)
    {
        $this->project = $project;
        $this->name = mb_strtolower($name);
        $this->provider = $provider;
        $this->currency = $currency;
        $this->simulated = $simulated;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTld(): string
    {
        $parts = explode('.', $this->name, 2);

        return '.'.($parts[1] ?? '');
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getStatus(): DomainStatus
    {
        return $this->status;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    /** @return list<string> */
    public function getNameservers(): array
    {
        return $this->nameservers;
    }

    /** @return list<array{type: string, name: string, value: string, ttl?: int}> */
    public function getDnsRecords(): array
    {
        return $this->dnsRecords;
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @param list<string> $nameservers
     */
    public function markRegistered(string $externalId, int $cost, \DateTimeImmutable $expiresAt, array $nameservers): void
    {
        $this->status = DomainStatus::Registered;
        $this->externalId = $externalId;
        $this->cost = $cost;
        $this->registeredAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->nameservers = $nameservers;
    }

    public function markExternal(): void
    {
        $this->status = DomainStatus::External;
    }

    /**
     * @param list<array{type: string, name: string, value: string, ttl?: int}> $records
     */
    public function configureDns(array $records): void
    {
        $this->dnsRecords = $records;
        $this->status = DomainStatus::Active;
    }

    public function markFailed(): void
    {
        $this->status = DomainStatus::Failed;
    }
}
