<?php

declare(strict_types=1);

namespace App\Provider\Mock;

use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared behaviour of mock providers:
 * - simulated latency (MZIAN_MOCK_LATENCY_MS) to make the pipeline observable;
 * - failure simulation configured in the provider settings, e.g.
 *   {"simulate_failures": {"create_hosting": 2}} fails the first two attempts
 *   (transient error), {"simulate_failures": {"register": "permanent"}} always fails.
 */
final readonly class MockBehavior
{
    public function __construct(
        private MockResourceStore $store,
        #[Autowire('%env(int:default::MZIAN_MOCK_LATENCY_MS)%')]
        private ?int $latencyMs = 0,
    ) {
    }

    public function simulate(Provider $provider, string $operation, string $idempotencyKey): void
    {
        if (($this->latencyMs ?? 0) > 0) {
            usleep(min(10_000, (int) $this->latencyMs) * 1000);
        }

        $failures = $provider->getSettings()['simulate_failures'][$operation] ?? null;
        if (null === $failures) {
            return;
        }
        if ('permanent' === $failures) {
            throw ProviderException::permanent(\sprintf('[simulated] %s rejected by %s.', $operation, $provider->getCode()), $provider->getCode());
        }
        $attempt = $this->store->attempt($provider->getCode(), $operation, $idempotencyKey);
        if ($attempt <= (int) $failures) {
            throw ProviderException::transient(\sprintf('[simulated] %s temporarily unavailable at %s (attempt %d).', $operation, $provider->getCode(), $attempt), $provider->getCode());
        }
    }
}
