<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Dto\AIRequest;
use App\AI\Dto\AIResponse;
use App\Provider\Entity\Provider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An AI provider driver (OpenAI, Claude, Mock...). The application never talks to
 * a vendor SDK directly: it goes through AIGateway, which picks the configured driver.
 * See PROVIDERS.md to add a new one.
 */
#[AutoconfigureTag('mzian.ai_provider')]
interface AIProviderInterface
{
    /** Driver key matching Provider::$driver ("openai", "claude", "mock"). */
    public static function getDriver(): string;

    /**
     * @throws Exception\AIException
     */
    public function complete(AIRequest $request, Provider $provider): AIResponse;
}
