<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\AIProviderInterface;
use App\AI\Dto\AIRequest;
use App\AI\Dto\AIResponse;
use App\AI\Exception\AIException;
use App\Provider\Entity\Provider;

/**
 * Test double of a REAL (non simulated) AI provider: returns scripted data or fails,
 * and records the requests it received.
 */
final class ScriptedAIProvider implements AIProviderInterface
{
    /** @var list<array<string, mixed>|\Throwable> */
    public array $script = [];

    /** @var list<AIRequest> */
    public array $requests = [];

    public static function getDriver(): string
    {
        return 'scripted';
    }

    public function complete(AIRequest $request, Provider $provider): AIResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? new AIException('No scripted response left', false);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return new AIResponse((string) json_encode($next), $next, $provider->getCode(), 'scripted-model', 1200, 300);
    }
}
