<?php

declare(strict_types=1);

namespace App\AI\Dto;

final readonly class AIResponse
{
    /**
     * @param array<string, mixed>|null $data decoded JSON output (structured tasks)
     */
    public function __construct(
        public string $text,
        public ?array $data,
        public string $provider,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $costCents = 0,
        public int $durationMs = 0,
        public bool $simulated = false,
    ) {
    }

    public function withCost(int $costCents, int $durationMs): self
    {
        return new self($this->text, $this->data, $this->provider, $this->model, $this->inputTokens, $this->outputTokens, $costCents, $durationMs, $this->simulated);
    }

    /**
     * @return array{provider: string, model: string, input_tokens: int, output_tokens: int, cost_cents: int, duration_ms: int, simulated: bool}
     */
    public function usage(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost_cents' => $this->costCents,
            'duration_ms' => $this->durationMs,
            'simulated' => $this->simulated,
        ];
    }
}
