<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\Import\CatalogImporter;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Setup\ProvidersSetup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports the real catalog and providers (config/mzian/*.yaml) inside the test transaction.
 */
trait PlatformFixtureTrait
{
    protected function setUpPlatform(): void
    {
        static::getContainer()->get(CatalogImporter::class)->import();
        static::getContainer()->get(ProvidersSetup::class)->import();
    }

    /**
     * Makes the scripted (non simulated) AI driver the default AI provider.
     *
     * @param array<string, mixed> $settings
     */
    protected function useScriptedAi(array $settings = ['price_per_million_tokens' => ['input' => 3.0, 'output' => 15.0]]): ScriptedAIProvider
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Provider::class)->findBy(['type' => ProviderType::Ai]) as $provider) {
            $provider->setDefault(false);
        }
        $provider = new Provider('scripted_ai', ProviderType::Ai, 'scripted', 'Scripted AI', ProvisioningMethod::Api);
        $provider->setSettings($settings);
        $provider->setDefault(true);
        $em->persist($provider);
        $em->flush();

        /** @var ScriptedAIProvider $driver */
        $driver = static::getContainer()->get(ScriptedAIProvider::class);

        return $driver;
    }
}
