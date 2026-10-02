<?php

declare(strict_types=1);

namespace App\Requirement\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DetailsData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    public ?string $businessName = null;

    #[Assert\Length(max: 120)]
    public ?string $city = null;

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    #[Assert\Length(max: 253)]
    #[Assert\Regex(pattern: '/^$|^(https?:\/\/)?(www\.)?([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,24}\/?$/', message: 'questionnaire.error.domain')]
    public ?string $desiredDomain = null;
}
