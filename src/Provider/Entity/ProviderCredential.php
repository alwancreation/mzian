<?php

declare(strict_types=1);

namespace App\Provider\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Encrypted secret of a provider (API key, token...). The clear value is never
 * stored, logged nor sent to an AI model; it is decrypted in memory by the
 * CredentialVault only when a provider driver needs it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'provider_credential')]
#[ORM\UniqueConstraint(name: 'uniq_provider_credential_name', fields: ['provider', 'name'])]
class ProviderCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Provider::class, inversedBy: 'credentials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Provider $provider;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedValue;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $rotatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(Provider $provider, string $name, string $encryptedValue)
    {
        $this->provider = $provider;
        $this->name = $name;
        $this->encryptedValue = $encryptedValue;
        $this->createdAt = new \DateTimeImmutable();
        $this->rotatedAt = $this->createdAt;
        $provider->addCredential($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEncryptedValue(): string
    {
        return $this->encryptedValue;
    }

    public function rotate(string $encryptedValue): void
    {
        $this->encryptedValue = $encryptedValue;
        $this->rotatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRotatedAt(): \DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
