<?php

declare(strict_types=1);

namespace App\AI\Dto;

/**
 * Provider-agnostic AI request.
 *
 * - task: logical task name (requirement_analysis, conversation_turn, content_generation...)
 * - jsonSchema: when set, the provider must return JSON matching it (validated afterwards)
 * - context: structured, NON-secret input data. Real providers ignore it (the prompt
 *   already contains the data); the deterministic mock provider uses it.
 */
final readonly class AIRequest
{
    /**
     * @param list<AIMessage>           $messages
     * @param array<string, mixed>|null $jsonSchema
     * @param array<string, mixed>      $context
     */
    public function __construct(
        public string $task,
        public string $systemPrompt,
        public array $messages,
        public ?array $jsonSchema = null,
        public string $schemaName = 'result',
        public int $maxTokens = 1500,
        public float $temperature = 0.2,
        public array $context = [],
    ) {
    }

    public function expectsJson(): bool
    {
        return null !== $this->jsonSchema;
    }
}
