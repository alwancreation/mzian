<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\AIProviderInterface;
use App\AI\Analysis\RequirementInput;
use App\AI\Analysis\RuleBasedAnalyzer;
use App\AI\Conversation\MockConversationResponder;
use App\AI\Dto\AIRequest;
use App\AI\Dto\AIResponse;
use App\AI\Exception\AIException;
use App\AI\Mock\MockContentGenerator;
use App\Provider\Entity\Provider;

/**
 * Offline, deterministic AI provider for development, demos and tests.
 * It does not pretend to be a language model: it answers each task with the
 * rule-based engines (catalog rules, multilingual keyword extraction, templates)
 * and always reports itself as "simulated".
 */
final readonly class MockAIProvider implements AIProviderInterface
{
    public function __construct(
        private RuleBasedAnalyzer $analyzer,
        private MockConversationResponder $conversation,
        private MockContentGenerator $content,
    ) {
    }

    public static function getDriver(): string
    {
        return 'mock';
    }

    public function complete(AIRequest $request, Provider $provider): AIResponse
    {
        $data = match ($request->task) {
            'requirement_analysis' => $this->analyzer->analyze(RequirementInput::fromArray((array) ($request->context['input'] ?? []))),
            'conversation_turn' => $this->conversation->respond((array) ($request->context['state'] ?? []), (string) ($request->context['message'] ?? '')),
            'content_generation' => $this->content->generate((array) ($request->context['business'] ?? [])),
            default => null,
        };
        if (null === $data && $request->expectsJson()) {
            throw new AIException(\sprintf('The mock AI provider does not support the task "%s".', $request->task), false, $provider->getCode());
        }

        $text = null !== $data ? (string) json_encode($data, \JSON_UNESCAPED_UNICODE) : 'Mock AI response for '.$request->task;
        $inputTokens = (int) ceil(mb_strlen($request->systemPrompt.implode('', array_map(static fn ($m) => $m->content, $request->messages))) / 4);

        return new AIResponse($text, $data, $provider->getCode(), 'mzian-rules-v1', $inputTokens, (int) ceil(mb_strlen($text) / 4), 0, 0, true);
    }
}
