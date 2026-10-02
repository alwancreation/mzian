<?php

declare(strict_types=1);

namespace App\AI\Conversation;

final readonly class ConversationTurn
{
    public function __construct(
        public string $reply,
        public bool $ready,
        public ?string $nextQuestionKey,
        public string $engine,
        public bool $fallbackUsed = false,
    ) {
    }
}
