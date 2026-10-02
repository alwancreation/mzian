<?php

declare(strict_types=1);

namespace App\Provider;

use App\Project\Entity\Project;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Exception\ProviderNotConfiguredException;
use App\Provider\Repository\ProviderRepository;

/**
 * Chooses which configured provider (Provider entity) handles an operation:
 * the project override chosen by an admin, else the default enabled provider
 * of that type, else the enabled one with the highest priority.
 */
final readonly class ProviderRegistry
{
    public function __construct(private ProviderRepository $providers)
    {
    }

    public function resolve(ProviderType $type, ?Project $project = null): Provider
    {
        $override = $project?->getProviderOverride($type->value);
        if (null !== $override) {
            $provider = $this->providers->findOneBy(['code' => $override, 'type' => $type, 'enabled' => true]);
            if (null !== $provider) {
                return $provider;
            }
        }

        $candidates = $this->providers->findBy(['type' => $type, 'enabled' => true], ['isDefault' => 'DESC', 'priority' => 'DESC', 'id' => 'ASC']);
        if ([] === $candidates) {
            throw new ProviderNotConfiguredException(\sprintf('No enabled %s provider is configured (Admin > Providers).', $type->value));
        }

        return $candidates[0];
    }

    public function findByCode(string $code): ?Provider
    {
        return $this->providers->findOneBy(['code' => $code]);
    }

    /**
     * @return list<Provider>
     */
    public function enabled(ProviderType $type): array
    {
        return $this->providers->findBy(['type' => $type, 'enabled' => true], ['isDefault' => 'DESC', 'priority' => 'DESC']);
    }
}
