<?php

declare(strict_types=1);

namespace App\Tests\Integration\Hosting;

use App\Hosting\Entity\HostingAccount;
use App\Hosting\Entity\HostingPlan;
use App\Hosting\Provider\MockHostingProvider;
use App\Provider\Entity\MockResource;
use App\Provider\Exception\ProviderException;
use App\Provider\Mock\MockBehavior;
use App\Provider\Mock\MockResourceStore;
use App\Provider\ProviderRegistry;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MockHostingProviderTest extends KernelTestCase
{
    use PlatformFixtureTrait;

    private MockHostingProvider $hosting;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $store = new MockResourceStore(static::getContainer()->get(EntityManagerInterface::class));
        $this->hosting = new MockHostingProvider($store, new MockBehavior($store, 0));
    }

    public function testAccountProvisioningIsIdempotentAndTheSecretIsReturnedOnce(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = static::getContainer()->get(ProviderRegistry::class)->findByCode('mock_hosting');
        self::assertNotNull($provider);
        $plan = $em->getRepository(HostingPlan::class)->findOneBy(['provider' => $provider, 'code' => 'business']);
        self::assertNotNull($plan);
        $project = (new \App\Tests\Support\Factory($em, static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)))->project();

        $first = $this->hosting->provisionAccount($provider, $plan, $project, 'MZ-T-hosting');
        $retry = $this->hosting->provisionAccount($provider, $plan, $project, 'MZ-T-hosting');

        self::assertSame($first->externalId, $retry->externalId);
        self::assertSame(7200, $first->cost, 'Business plan: $72 a year.');
        self::assertNotNull($first->password());
        self::assertNull($retry->password(), 'Like real providers, the password is only returned at creation.');
        self::assertStringNotContainsString((string) $first->password(), print_r($first, true), 'Secrets never appear in dumps/logs.');
        self::assertSame(1, $em->getRepository(MockResource::class)->count(['kind' => 'hosting_account']));

        $account = new HostingAccount($project, 'mock_hosting', $plan, $first->externalId, $first->cost, 'USD', 'MZ-T-hosting', true);
        $account->activate($first->username, $first->region, $first->ipAddress, $first->controlPanelUrl, $first->expiresAt);
        $site = $this->hosting->createSite($provider, $account, 'atlas-cars.com', ['database' => true], 'MZ-T-site');
        self::assertStringEndsWith('/atlas-cars.com/public', $site->documentRoot);
        self::assertNotNull($site->databaseName);
        self::assertSame($site->externalId, $this->hosting->createSite($provider, $account, 'atlas-cars.com', ['database' => true], 'MZ-T-site')->externalId);
    }

    public function testSimulatedOutage(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = static::getContainer()->get(ProviderRegistry::class)->findByCode('mock_hosting');
        $provider?->setSettings(['simulate_failures' => ['create_account' => 'permanent']]);
        $plan = $em->getRepository(HostingPlan::class)->findOneBy(['provider' => $provider]);
        $project = (new \App\Tests\Support\Factory($em, static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)))->project();

        $this->expectException(ProviderException::class);
        $this->hosting->provisionAccount($provider, $plan, $project, 'MZ-T-down');
    }
}
