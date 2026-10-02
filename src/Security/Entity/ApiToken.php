<?php

declare(strict_types=1);

namespace App\Security\Entity;

use App\Security\Repository\ApiTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bearer token for the /api/v1 endpoints. Only a SHA-256 hash is stored:
 * the clear token is shown once at creation time.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
class ApiToken
{
    public const PREFIX = 'mzn_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(length: 16)]
    private string $tokenPrefix;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(User $user, string $name, string $tokenHash, string $tokenPrefix, ?\DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @return array{0: self, 1: string} the entity and the clear token (to display once)
     */
    public static function generate(User $user, string $name, ?\DateTimeImmutable $expiresAt = null): array
    {
        $clear = self::PREFIX.bin2hex(random_bytes(24));

        return [new self($user, $name, self::hash($clear), substr($clear, 0, 12), $expiresAt), $clear];
    }

    public static function hash(string $clearToken): string
    {
        return hash('sha256', $clearToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isValid(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return null === $this->revokedAt
            && (null === $this->expiresAt || $this->expiresAt > $now)
            && $this->user->isActive();
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }
}
