<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminDecisionRequest
{
    /** Note (approve), question (request-changes) or reason (reject/cancel). */
    #[Assert\Length(max: 2000)]
    public ?string $message = null;

    public bool $refund = true;

    /** Automation status to resume/retry at (default: where it stopped). */
    #[Assert\Regex('/^[A-Z_]{2,40}$/')]
    public ?string $at = null;
}
