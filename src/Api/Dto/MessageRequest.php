<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class MessageRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    public string $content = '';
}
