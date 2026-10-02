<?php

declare(strict_types=1);

namespace App\Provider\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable state of the mock providers (simulated hosting accounts, domains...),
 * keyed by idempotency key so that mocks behave like real idempotent APIs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mock_provider_resource')]
#[ORM\UniqueConstraint(name: 'uniq_mock_resource_key', fields: ['provider', 'kind', 'idempotencyKey'])]
class MockResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $provider;

    #[ORM\Column(length: 40)]
    private string $kind;

    #[ORM\Column(length: 160)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $externalId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $provider, string $kind, string $idempotencyKey, string $externalId, array $payload)
    {
        $this->provider = $provider;
        $this->kind = $kind;
        $this->idempotencyKey = $idempotencyKey;
        $this->externalId = $externalId;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
