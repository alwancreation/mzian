<?php

declare(strict_types=1);

namespace App\Api\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Global rate limit of the API (config/packages/rate_limiter.yaml, "api"), per API
 * token (hashed, never stored in clear) or per IP for anonymous calls. Runs before
 * authentication so that token guessing is throttled too. Endpoints that cost money
 * (AI analysis, chat) have their own, stricter limits.
 */
final readonly class ApiRateLimitSubscriber
{
    public function __construct(
        #[Autowire(service: 'limiter.api')]
        private RateLimiterFactoryInterface $apiLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $authorization = (string) $request->headers->get('Authorization', '');
        $key = '' !== $authorization ? 'token-'.hash('sha256', $authorization) : 'ip-'.($request->getClientIp() ?? 'unknown');
        $limit = $this->apiLimiter->create($key)->consume();
        if ($limit->isAccepted()) {
            return;
        }
        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $event->setResponse(new JsonResponse(
            ['error' => ['code' => 429, 'message' => 'Too many requests, retry later.']],
            429,
            ['Retry-After' => (string) $retryAfter, 'X-RateLimit-Limit' => (string) $limit->getLimit(), 'X-RateLimit-Remaining' => '0'],
        ));
    }
}
