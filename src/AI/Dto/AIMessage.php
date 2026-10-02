<?php

declare(strict_types=1);

namespace App\AI\Dto;

final readonly class AIMessage
{
    public const USER = 'user';
    public const ASSISTANT = 'assistant';

    public function __construct(
        public string $role,
        public string $content,
    ) {
    }

    public static function user(string $content): self
    {
        return new self(self::USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ASSISTANT, $content);
    }
}
