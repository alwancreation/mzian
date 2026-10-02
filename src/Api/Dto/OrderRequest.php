<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final class OrderRequest
{
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-f0-9]{32}$/')]
    public string $quote = '';

    /** Plan code, "none", or null to keep the recommended plan. */
    #[Assert\Regex('/^[a-z0-9_]{2,60}$/')]
    public ?string $subscription = null;

    #[SerializedName('payment_provider')]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9_]{2,60}$/')]
    public string $paymentProvider = '';

    #[SerializedName('accept_terms')]
    #[Assert\IsTrue(message: 'The terms of service must be accepted.')]
    public bool $acceptTerms = false;
}
