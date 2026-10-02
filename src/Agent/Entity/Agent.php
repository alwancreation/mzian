<?php

declare(strict_types=1);

namespace App\Agent\Entity;

use App\Agent\Repository\AgentRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registry/configuration of an AI agent. "permissions" is an explicit allow-list
 * (least privilege): an agent can only do what is listed, and never what is
 * reserved to human administrators (approve, reject, resume, change budget...).
 */
#[ORM\Entity(repositoryClass: AgentRepository::class)]
#[ORM\Table(name: 'agent')]
#[ORM\HasLifecycleCallbacks]
class Agent
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 500)]
    private string $description;

    #[ORM\Column]
    private bool $enabled = true;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions;

    #[ORM\Column]
    private int $maxAttempts = 3;

    /**
     * @param list<string> $permissions
     */
    public function __construct(string $code, string $name, string $description, array $permissions)
    {
        $this->code = $code;
        $this->name = $name;
        $this->description = $description;
        $this->permissions = $permissions;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function isAllowed(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @param list<string> $permissions
     */
    public function update(string $name, string $description, array $permissions, int $maxAttempts): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->permissions = $permissions;
        $this->maxAttempts = max(1, $maxAttempts);
    }
}
