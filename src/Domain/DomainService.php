<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Dto\DomainAvailability;
use App\Domain\Provider\DomainProviderInterface;
use App\Project\Entity\Project;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Exception\ProviderException;
use App\Provider\ProviderRegistry;
use App\Requirement\Service\RequirementService;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Domain names: availability, prices and suggestions through the configured registrar.
 */
final readonly class DomainService
{
    public function __construct(
        private ProviderRegistry $providers,
        #[AutowireLocator('mzian.domain_provider', defaultIndexMethod: 'getDriver')]
        private ContainerInterface $drivers,
    ) {
    }

    public function provider(?Project $project = null): Provider
    {
        return $this->providers->resolve(ProviderType::Domain, $project);
    }

    public function driver(Provider $provider): DomainProviderInterface
    {
        if (!$this->drivers->has($provider->getDriver())) {
            throw ProviderException::permanent(\sprintf('No domain driver "%s" is installed.', $provider->getDriver()), $provider->getCode());
        }

        return $this->drivers->get($provider->getDriver());
    }

    public function check(string $domain, ?Project $project = null): DomainAvailability
    {
        $provider = $this->provider($project);

        return $this->driver($provider)->checkAvailability($provider, $domain);
    }

    /**
     * Estimated yearly price of a TLD with the current registrar (null if unknown).
     */
    public function estimate(string $tld, ?Project $project = null): ?int
    {
        try {
            $provider = $this->provider($project);

            return $this->driver($provider)->tldPrice($provider, $tld);
        } catch (ProviderException) {
            return null;
        }
    }

    /**
     * First available name among: the desired one, then variants of the business name.
     *
     * @param list<string> $preferredTlds
     */
    public function suggest(?string $desired, ?string $businessName, ?string $city, array $preferredTlds = ['.com']): ?DomainAvailability
    {
        $candidates = [];
        if (null !== $desired && RequirementService::isValidDomain(RequirementService::normalizeDomain($desired))) {
            $candidates[] = RequirementService::normalizeDomain($desired);
        }
        $slug = strtolower((string) (new AsciiSlugger())->slug((string) $businessName));
        $slug = trim(substr($slug, 0, 40), '-');
        if (\strlen($slug) >= 3) {
            $citySlug = strtolower((string) (new AsciiSlugger())->slug((string) $city));
            foreach ($preferredTlds ?: ['.com'] as $tld) {
                $candidates[] = $slug.$tld;
            }
            foreach ($preferredTlds ?: ['.com'] as $tld) {
                if ('' !== $citySlug) {
                    $candidates[] = $slug.'-'.$citySlug.$tld;
                }
                $candidates[] = $slug.'-online'.$tld;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            try {
                $availability = $this->check($candidate);
                if ($availability->available) {
                    return $availability;
                }
            } catch (ProviderException) {
                continue;
            }
        }

        return null;
    }
}
