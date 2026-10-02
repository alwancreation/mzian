<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ConversationRequest
{
    #[Assert\Choice(choices: ['fr', 'en', 'ar'])]
    public string $locale = 'fr';

    #[Assert\Length(max: 2000)]
    public ?string $message = null;
}
