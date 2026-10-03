<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Customer\Entity\Customer;
use App\Security\UserManager;
use App\Tests\Support\PlatformFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API v1: conversation → quote → order → project status, with customer API tokens.
 */
final class CommerceApiTest extends WebTestCase
{
    use PlatformFixtureTrait;
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setUpPlatform();
    }

    private function token(Customer $customer): string
    {
        return static::getContainer()->get(UserManager::class)->createApiToken($customer->getUser(), 'test')[1];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, ?string $token, ?array $body = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, server: $server, content: null !== $body ? (string) json_encode($body) : null);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testCustomerQuotesOrdersAndFollowsAProject(): void
    {
        $token = $this->token($this->factory()->customer('api@example.com'));

        $conversation = $this->api('POST', '/api/v1/conversations', $token, ['message' => "J'ai une agence de location de voitures à Agadir avec 12 voitures, je veux des réservations en ligne et gérer les contrats.", 'locale' => 'fr']);
        self::assertResponseStatusCodeSame(201);

        $quote = $this->api('POST', '/api/v1/quotes', $token, ['conversation' => $conversation['token']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('car_rental_management', $quote['solution']['code']);
        self::assertGreaterThan(0, $quote['price']);
        self::assertSame($quote['price'], array_sum(array_map(static fn ($l) => $l['recurring'] ? 0 : $l['price'], $quote['lines'])));
        $json = (string) $this->client->getResponse()->getContent();
        foreach (['margin', 'cost', 'cost_price'] as $internal) {
            self::assertStringNotContainsString('"'.$internal.'"', $json, 'Internal costs are never exposed.');
        }

        $this->api('POST', '/api/v1/orders', $token, ['quote' => $quote['token'], 'payment_provider' => 'mock_payment', 'accept_terms' => false]);
        self::assertResponseStatusCodeSame(422);

        $order = $this->api('POST', '/api/v1/orders', $token, ['quote' => $quote['token'], 'payment_provider' => 'mock_payment', 'subscription' => 'none', 'accept_terms' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending_payment', $order['status']);
        self::assertSame(0, $order['recurring_monthly']);
        self::assertStringContainsString('/fr/paiement-simule/pay-'.$order['number'].'-1', $order['payment']['checkout_url']);

        $status = $this->api('GET', '/api/v1/projects/'.$order['project'].'/status', $token);
        self::assertResponseIsSuccessful();
        self::assertSame('ORDERED', $status['status']);
        self::assertSame(['order', 'quote', 'analyze'], array_column($status['events'], 'transition'));

        $list = $this->api('GET', '/api/v1/orders', $token);
        self::assertCount(1, $list['orders']);
        $projects = $this->api('GET', '/api/v1/projects', $token);
        self::assertSame($order['project'], $projects['projects'][0]['reference']);

        // Another customer sees nothing.
        $intruder = $this->token($this->factory()->customer('intruder@example.com'));
        $this->api('GET', '/api/v1/quotes/'.$quote['token'], $intruder);
        self::assertResponseStatusCodeSame(404);
        $this->api('GET', '/api/v1/orders/'.$order['number'], $intruder);
        self::assertResponseStatusCodeSame(404);
        $this->api('GET', '/api/v1/projects/'.$order['project'], $intruder);
        self::assertResponseStatusCodeSame(403);
        $this->api('POST', '/api/v1/quotes', $intruder, ['conversation' => $conversation['token']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCommerceEndpointsRequireAToken(): void
    {
        foreach (['/api/v1/orders', '/api/v1/projects'] as $uri) {
            $body = $this->api('GET', $uri, null);
            self::assertResponseStatusCodeSame(401);
            self::assertSame(401, $body['error']['code'] ?? null, 'JSON error, not an HTML page.');
            self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="mzian-api"');
        }
        $body = $this->api('POST', '/api/v1/quotes', 'mzn_invalid', ['conversation' => str_repeat('a', 32)]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Invalid or expired API token.', $body['error']['message'] ?? null);

        // A customer token cannot reach the administration API.
        $body = $this->api('GET', '/api/v1/admin/approvals', $this->token($this->factory()->customer('karim@atlas.ma')));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['code' => 403, 'message' => 'Access denied.'], $body['error'] ?? null);
    }
}
