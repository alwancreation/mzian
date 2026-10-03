<?php

declare(strict_types=1);

namespace App\Web\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness of the platform for deployments, load balancers and monitoring:
 * the application answers and reaches its database. The "app" marker lets a
 * check tell this platform from any other site answering on the same address.
 * No version, host or error detail is exposed.
 */
#[AsController]
final readonly class HealthController
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/healthz', name: 'healthz', methods: ['GET', 'HEAD'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $status = 200;
        } catch (\Throwable $e) {
            $this->logger->error('Health check: database unreachable.', ['exception' => $e]);
            $status = 503;
        }

        return new JsonResponse(
            ['app' => 'mzian', 'status' => 200 === $status ? 'ok' : 'unavailable'],
            $status,
            ['Cache-Control' => 'no-store'],
        );
    }
}
