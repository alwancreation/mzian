<?php

declare(strict_types=1);

namespace App\Analytics\Entity;

use App\Analytics\Repository\VisitRepository;
use App\Lead\Enum\LeadSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per unique visitor per day (privacy friendly: no cookie, no raw IP;
 * the visitor hash is derived from a daily-rotating salt).
 */
#[ORM\Entity(repositoryClass: VisitRepository::class)]
#[ORM\Table(name: 'visit')]
#[ORM\UniqueConstraint(name: 'uniq_visit_visitor_day', fields: ['visitorHash', 'day'])]
#[ORM\Index(name: 'idx_visit_day', fields: ['day'])]
class Visit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $visitorHash;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 255)]
    private string $landingPath;

    #[ORM\Column(length: 20, enumType: LeadSource::class)]
    private LeadSource $source;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $referrerHost;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $visitorHash, string $landingPath, LeadSource $source, ?string $referrerHost, string $locale)
    {
        $this->visitorHash = $visitorHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->day = $this->createdAt->setTime(0, 0);
        $this->landingPath = mb_substr($landingPath, 0, 255);
        $this->source = $source;
        $this->referrerHost = null !== $referrerHost ? mb_substr($referrerHost, 0, 120) : null;
        $this->locale = $locale;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitorHash(): string
    {
        return $this->visitorHash;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getLandingPath(): string
    {
        return $this->landingPath;
    }

    public function getSource(): LeadSource
    {
        return $this->source;
    }

    public function getReferrerHost(): ?string
    {
        return $this->referrerHost;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
