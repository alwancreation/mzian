<?php

declare(strict_types=1);

namespace App\Provider\Mock;

use App\Provider\Entity\MockResource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Durable state of the mock providers, keyed by (provider, kind, idempotency key),
 * so that simulated APIs are idempotent exactly like real ones should be.
 */
final readonly class MockResourceStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $provider, string $kind, string $key): ?MockResource
    {
        return $this->em->getRepository(MockResource::class)->findOneBy(['provider' => $provider, 'kind' => $kind, 'idempotencyKey' => $key]);
    }

    /**
     * @param array<string, mixed> $criteria payload values to match
     */
    public function findByPayload(string $provider, string $kind, array $criteria): ?MockResource
    {
        foreach ($this->em->getRepository(MockResource::class)->findBy(['provider' => $provider, 'kind' => $kind]) as $resource) {
            if (array_intersect_assoc($criteria, $resource->getPayload()) === $criteria) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $provider, string $kind, string $key, string $externalId, array $payload): MockResource
    {
        $resource = $this->find($provider, $kind, $key);
        if (null === $resource) {
            $resource = new MockResource($provider, $kind, $key, $externalId, $payload);
            $this->em->persist($resource);
        } else {
            $resource->setPayload($payload);
        }
        $this->em->flush();

        return $resource;
    }

    /**
     * Increments and returns the number of attempts of an operation.
     */
    public function attempt(string $provider, string $operation, string $key): int
    {
        $resource = $this->find($provider, 'attempts', $operation.':'.$key);
        $count = (int) ($resource?->getPayload()['count'] ?? 0) + 1;
        $this->save($provider, 'attempts', $operation.':'.$key, 'attempts', ['count' => $count]);

        return $count;
    }
}
