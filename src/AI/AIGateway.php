<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Dto\AIRequest;
use App\AI\Dto\AIResponse;
use App\AI\Exception\AIException;
use App\AI\Exception\InvalidAIOutputException;
use App\AI\Schema\JsonSchemaValidator;
use App\Project\Entity\Project;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\ProviderRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Single entry point for every AI call: resolves the configured provider (Admin >
 * Providers), calls its driver, validates structured output against its JSON schema,
 * estimates the cost and logs usage. Business code never depends on a vendor.
 */
final readonly class AIGateway
{
    public function __construct(
        private ProviderRegistry $providers,
        #[AutowireLocator('mzian.ai_provider', defaultIndexMethod: 'getDriver')]
        private ContainerInterface $drivers,
        private JsonSchemaValidator $schemas,
        private LoggerInterface $aiLogger,
    ) {
    }

    /**
     * @throws AIException
     */
    public function complete(AIRequest $request, ?Project $project = null): AIResponse
    {
        $provider = $this->providers->resolve(ProviderType::Ai, $project);

        return $this->completeWith($provider, $request);
    }

    /**
     * @throws AIException
     */
    public function completeWith(Provider $provider, AIRequest $request): AIResponse
    {
        if (!$this->drivers->has($provider->getDriver())) {
            throw new AIException(\sprintf('No AI driver "%s" is installed.', $provider->getDriver()), false, $provider->getCode());
        }
        /** @var AIProviderInterface $driver */
        $driver = $this->drivers->get($provider->getDriver());

        $start = hrtime(true);
        try {
            $response = $driver->complete($request, $provider);
        } catch (AIException $e) {
            $this->aiLogger->warning('AI call failed', ['task' => $request->task, 'provider' => $provider->getCode(), 'retryable' => $e->retryable, 'error' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            $this->aiLogger->error('AI driver error', ['task' => $request->task, 'provider' => $provider->getCode(), 'error' => $e->getMessage()]);
            throw new AIException('AI provider error: '.$e->getMessage(), true, $provider->getCode(), $e);
        }
        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
        $response = $response->withCost($this->estimateCost($provider, $response), $durationMs);

        if (null !== $request->jsonSchema) {
            if (null === $response->data) {
                throw new InvalidAIOutputException('The AI did not return JSON', [], $provider->getCode());
            }
            $errors = $this->schemas->validate($response->data, $request->jsonSchema);
            if ([] !== $errors) {
                $this->aiLogger->warning('AI output rejected by schema', ['task' => $request->task, 'provider' => $provider->getCode(), 'errors' => \array_slice($errors, 0, 5)]);
                throw new InvalidAIOutputException('The AI output does not match the expected schema', $errors, $provider->getCode());
            }
        }

        $this->aiLogger->info('AI call', ['task' => $request->task] + $response->usage());

        return $response;
    }

    /**
     * Cost in minor units from the provider's configured price per million tokens.
     */
    private function estimateCost(Provider $provider, AIResponse $response): int
    {
        $prices = $provider->getSettings()['price_per_million_tokens'] ?? null;
        if (!\is_array($prices)) {
            return 0;
        }
        $usd = ($response->inputTokens * (float) ($prices['input'] ?? 0) + $response->outputTokens * (float) ($prices['output'] ?? 0)) / 1_000_000;

        return (int) ceil($usd * 100);
    }
}
