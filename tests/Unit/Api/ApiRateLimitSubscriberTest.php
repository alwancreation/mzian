<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\EventSubscriber\ApiRateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ApiRateLimitSubscriberTest extends TestCase
{
    public function testTheApiIsLimitedPerTokenAndPerIp(): void
    {
        $subscriber = new ApiRateLimitSubscriber(new RateLimiterFactory(['id' => 'api', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage()));
        $call = static function (string $path, ?string $token = null) use ($subscriber): ?int {
            $request = Request::create($path, server: ['REMOTE_ADDR' => '203.0.113.7'] + (null !== $token ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token] : []));
            $event = new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
            $subscriber->onRequest($event);

            return $event->getResponse()?->getStatusCode();
        };

        self::assertNull($call('/api/v1/projects', 'mzn_a'));
        self::assertNull($call('/api/v1/projects', 'mzn_a'));
        self::assertSame(429, $call('/api/v1/projects', 'mzn_a'));
        self::assertNull($call('/api/v1/projects', 'mzn_b'), 'Another token has its own quota.');
        self::assertNull($call('/api/v1/solutions'));
        self::assertNull($call('/api/v1/solutions'));
        self::assertSame(429, $call('/api/v1/solutions'), 'Anonymous calls are limited per IP.');
        self::assertNull($call('/fr/'), 'Only the API is concerned.');
    }

    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response();
            }
        };
    }
}
