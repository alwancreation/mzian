<?php

declare(strict_types=1);

namespace App\Security\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\Length(max: 80)]
    public ?string $lastName = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 4096, minMessage: 'registration.password.too_short')]
    #[Assert\NotCompromisedPassword(skipOnError: true)]
    public ?string $plainPassword = null;

    #[Assert\Length(max: 160)]
    public ?string $companyName = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[0-9+().\s-]*$/', message: 'registration.phone.invalid')]
    public ?string $phone = null;

    #[Assert\IsTrue(message: 'registration.terms.required')]
    public bool $acceptTerms = false;
}
