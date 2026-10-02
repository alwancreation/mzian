<?php

declare(strict_types=1);

namespace App\Web\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ContactData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[0-9+().\s-]*$/', message: 'registration.phone.invalid')]
    public ?string $phone = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 3000)]
    public ?string $message = null;

    /** Honeypot: must stay empty (bots fill every field). */
    #[Assert\Blank]
    public ?string $website = null;
}
