<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthEndpointIdentifiesThePlatformAndChecksTheDatabase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['app' => 'mzian', 'status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
