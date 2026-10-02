<?php

declare(strict_types=1);

namespace App\Project\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Credential delivered to the customer (application admin, hosting panel...).
 * The secret is encrypted at rest (SecretManager) and only decrypted when the
 * authenticated owner explicitly reveals it; every reveal is audited.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_credential')]
class ProjectCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'credentials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 180)]
    private string $username;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedSecret;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url;

    #[ORM\Column]
    private int $revealCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRevealedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $type, string $label, string $username, string $encryptedSecret, ?string $url = null)
    {
        $this->project = $project;
        $this->type = $type;
        $this->label = $label;
        $this->username = $username;
        $this->encryptedSecret = $encryptedSecret;
        $this->url = $url;
        $this->createdAt = new \DateTimeImmutable();
        $project->addCredential($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEncryptedSecret(): string
    {
        return $this->encryptedSecret;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getRevealCount(): int
    {
        return $this->revealCount;
    }

    public function getLastRevealedAt(): ?\DateTimeImmutable
    {
        return $this->lastRevealedAt;
    }

    public function recordReveal(): void
    {
        ++$this->revealCount;
        $this->lastRevealedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
