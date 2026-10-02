<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class QuoteRequest
{
    /** Token of a conversation created with POST /api/v1/conversations. */
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-f0-9]{32}$/')]
    public string $conversation = '';
}
