<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\DomainService;
use App\Provider\Entity\MockResource;
use App\Provider\Exception\ProviderException;
use App\Provider\ProviderRegistry;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MockDomainProviderTest extends KernelTestCase
{
    use PlatformFixtureTrait;

    private DomainService $domains;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $this->domains = static::getContainer()->get(DomainService::class);
    }

    public function testAvailabilityAndTldPrices(): void
    {
        $free = $this->domains->check('https://www.Atlas-Cars.ma/');
        self::assertTrue($free->available);
        self::assertSame('atlas-cars.ma', $free->domain);
        self::assertSame(2500, $free->price);

        self::assertFalse($this->domains->check('google.com')->available);
        self::assertSame(1500, $this->domains->estimate('.xyz'), 'Default price for unknown TLDs.');
    }

    public function testInvalidDomainIsAPermanentError(): void
    {
        try {
            $this->domains->check('not a domain');
            self::fail('Expected a provider exception.');
        } catch (ProviderException $e) {
            self::assertFalse($e->retryable);
        }
    }

    public function testRegistrationIsIdempotentAndNeverDuplicated(): void
    {
        $provider = $this->domains->provider();
        $driver = $this->domains->driver($provider);

        $first = $driver->register($provider, 'atlas-cars.com', 1, 'project-42-domain');
        $retry = $driver->register($provider, 'atlas-cars.com', 1, 'project-42-domain');

        self::assertSame($first->externalId, $retry->externalId);
        self::assertSame(1200, $first->cost);
        self::assertTrue($first->simulated);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(MockResource::class)->count(['kind' => 'domain']));

        // Once registered, the name is no longer available to anyone else.
        self::assertFalse($this->domains->check('atlas-cars.com')->available);
        $this->expectException(ProviderException::class);
        $driver->register($provider, 'atlas-cars.com', 1, 'another-project-domain');
    }

    public function testSimulatedTransientFailuresThenSuccess(): void
    {
        $provider = static::getContainer()->get(ProviderRegistry::class)->findByCode('mock_domain');
        self::assertNotNull($provider);
        $provider->setSettings(['simulate_failures' => ['register' => 1]] + $provider->getSettings());
        $driver = $this->domains->driver($provider);

        try {
            $driver->register($provider, 'retry-me.com', 1, 'key-retry');
            self::fail('First attempt should fail.');
        } catch (ProviderException $e) {
            self::assertTrue($e->retryable);
        }
        self::assertSame('retry-me.com', $driver->register($provider, 'retry-me.com', 1, 'key-retry')->domain);
    }

    public function testSuggestionSkipsTakenNames(): void
    {
        $suggestion = $this->domains->suggest('mzian.com', 'Mzian', 'Rabat', ['.com']);

        self::assertNotNull($suggestion);
        self::assertSame('mzian-rabat.com', $suggestion->domain);
    }
}
