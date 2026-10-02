<?php

declare(strict_types=1);

namespace App\Provider\Entity;

use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Repository\ProviderRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A configured external provider (Hostinger, Hetzner, Namecheap, Stripe, OpenAI...).
 * "driver" selects the PHP implementation; secrets live in ProviderCredential (encrypted).
 */
#[ORM\Entity(repositoryClass: ProviderRepository::class)]
#[ORM\Table(name: 'provider')]
#[ORM\Index(name: 'idx_provider_type', fields: ['type', 'enabled'])]
#[ORM\HasLifecycleCallbacks]
class Provider
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60, unique: true)]
    private string $code;

    #[ORM\Column(length: 20, enumType: ProviderType::class)]
    private ProviderType $type;

    #[ORM\Column(length: 40)]
    private string $driver;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private int $priority = 0;

    #[ORM\Column(length: 10, enumType: ProvisioningMethod::class)]
    private ProvisioningMethod $provisioningMethod;

    /** @var array<string, mixed> non-secret settings (region, organization, defaults...) */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = [];

    /** @var list<string> e.g. ["create_account", "ssl", "dns", "email"] */
    #[ORM\Column(type: Types::JSON)]
    private array $capabilities = [];

    /** @var Collection<int, ProviderCredential> */
    #[ORM\OneToMany(targetEntity: ProviderCredential::class, mappedBy: 'provider', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $credentials;

    public function __construct(string $code, ProviderType $type, string $driver, string $name, ProvisioningMethod $provisioningMethod)
    {
        $this->code = $code;
        $this->type = $type;
        $this->driver = $driver;
        $this->name = $name;
        $this->provisioningMethod = $provisioningMethod;
        $this->credentials = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getType(): ProviderType
    {
        return $this->type;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        if (!$enabled) {
            $this->isDefault = false;
        }
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getProvisioningMethod(): ProvisioningMethod
    {
        return $this->provisioningMethod;
    }

    public function isMock(): bool
    {
        return ProvisioningMethod::Mock === $this->provisioningMethod;
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /** @return list<string> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @param list<string> $capabilities
     */
    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function hasCapability(string $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }

    /** @return Collection<int, ProviderCredential> */
    public function getCredentials(): Collection
    {
        return $this->credentials;
    }

    public function getCredential(string $name): ?ProviderCredential
    {
        foreach ($this->credentials as $credential) {
            if ($credential->getName() === $name) {
                return $credential;
            }
        }

        return null;
    }

    public function addCredential(ProviderCredential $credential): void
    {
        if (!$this->credentials->contains($credential)) {
            $this->credentials->add($credential);
        }
    }
}
