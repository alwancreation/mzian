<?php

declare(strict_types=1);

namespace App\Customer\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ProfileData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\Length(max: 80)]
    public ?string $lastName = null;

    #[Assert\Length(max: 160)]
    public ?string $companyName = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[0-9+().\s-]*$/', message: 'registration.phone.invalid')]
    public ?string $phone = null;

    #[Assert\Length(max: 120)]
    public ?string $city = null;

    #[Assert\Choice(choices: ['fr', 'en', 'ar'])]
    public string $locale = 'fr';
}
