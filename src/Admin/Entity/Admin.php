<?php

declare(strict_types=1);

namespace App\Admin\Entity;

use App\Admin\Repository\AdminRepository;
use App\Security\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Profile of a human Mzian administrator (the only actor allowed to approve projects).
 */
#[ORM\Entity(repositoryClass: AdminRepository::class)]
#[ORM\Table(name: 'admin_profile')]
class Admin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'admin')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    private string $displayName;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $jobTitle;

    #[ORM\Column]
    private bool $receivesApprovalNotifications = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $displayName, ?string $jobTitle = null)
    {
        $this->user = $user;
        $user->setAdmin($this);
        $this->displayName = $displayName;
        $this->jobTitle = $jobTitle;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function receivesApprovalNotifications(): bool
    {
        return $this->receivesApprovalNotifications;
    }

    public function setReceivesApprovalNotifications(bool $value): void
    {
        $this->receivesApprovalNotifications = $value;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
