<?php

declare(strict_types=1);

namespace App\Requirement\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class LeadData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $fullName = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[0-9+().\s-]*$/', message: 'registration.phone.invalid')]
    public ?string $phone = null;

    #[Assert\IsTrue(message: 'registration.terms.required')]
    public bool $acceptPrivacy = false;

    public bool $marketingConsent = false;
}
