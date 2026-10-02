<?php

declare(strict_types=1);

namespace App\Lead\Entity;

use App\Customer\Entity\Customer;
use App\Lead\Enum\LeadSource;
use App\Lead\Enum\LeadStatus;
use App\Lead\Repository\LeadRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A prospect captured before any payment is requested (spec: "Avant de demander
 * le paiement, enregistrer le lead").
 */
#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: 'sales_lead')]
#[ORM\Index(name: 'idx_lead_email', fields: ['email'])]
#[ORM\Index(name: 'idx_lead_status', fields: ['status'])]
#[ORM\Index(name: 'idx_lead_source', fields: ['source'])]
#[ORM\Index(name: 'idx_lead_created', fields: ['createdAt'])]
#[ORM\HasLifecycleCallbacks]
class Lead
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $fullName;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $phone = null;

    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $companyName = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $sector = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 20, enumType: LeadSource::class)]
    private LeadSource $source = LeadSource::Direct;

    #[ORM\Column(length: 20, enumType: LeadStatus::class)]
    private LeadStatus $status = LeadStatus::New;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $utm = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referrer = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $landingPage = null;

    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    #[ORM\Column]
    private bool $marketingConsent = false;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    /** @var Collection<int, LeadActivity> */
    #[ORM\OneToMany(targetEntity: LeadActivity::class, mappedBy: 'lead', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $activities;

    public function __construct(string $email, string $fullName)
    {
        $this->email = mb_strtolower(trim($email));
        $this->fullName = $fullName;
        $this->activities = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = mb_strtolower(trim($email));
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): void
    {
        $this->fullName = $fullName;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): void
    {
        $this->companyName = $companyName;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(?string $sector): void
    {
        $this->sector = $sector;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getSource(): LeadSource
    {
        return $this->source;
    }

    public function setSource(LeadSource $source): void
    {
        $this->source = $source;
    }

    public function getStatus(): LeadStatus
    {
        return $this->status;
    }

    /**
     * Status only moves forward in the funnel (never back from converted to quoted).
     */
    public function advanceTo(LeadStatus $status): void
    {
        $order = [LeadStatus::New, LeadStatus::Engaged, LeadStatus::Quoted, LeadStatus::Converted];
        $current = array_search($this->status, $order, true);
        $target = array_search($status, $order, true);
        if (LeadStatus::Lost === $status || false === $current || (false !== $target && $target > $current)) {
            $this->status = $status;
        }
    }

    /** @return array<string, string> */
    public function getUtm(): array
    {
        return $this->utm;
    }

    /**
     * @param array<string, string> $utm
     */
    public function setUtm(array $utm): void
    {
        $this->utm = $utm;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(?string $referrer): void
    {
        $this->referrer = null !== $referrer ? mb_substr($referrer, 0, 255) : null;
    }

    public function getLandingPage(): ?string
    {
        return $this->landingPage;
    }

    public function setLandingPage(?string $landingPage): void
    {
        $this->landingPage = null !== $landingPage ? mb_substr($landingPage, 0, 255) : null;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function hasMarketingConsent(): bool
    {
        return $this->marketingConsent;
    }

    public function setMarketingConsent(bool $marketingConsent): void
    {
        $this->marketingConsent = $marketingConsent;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function convert(Customer $customer): void
    {
        $this->customer = $customer;
        $this->advanceTo(LeadStatus::Converted);
    }

    public function attachCustomer(Customer $customer): void
    {
        $this->customer = $customer;
    }

    /** @return Collection<int, LeadActivity> */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function addActivity(string $type, string $description, array $metadata = []): LeadActivity
    {
        $activity = new LeadActivity($this, $type, $description, $metadata);
        $this->activities->add($activity);

        return $activity;
    }
}
