<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Dto\DomainAvailability;
use App\Domain\Dto\DomainRegistration;
use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderException;
use App\Provider\Mock\MockBehavior;
use App\Provider\Mock\MockResourceStore;
use App\Requirement\Service\RequirementService;

/**
 * Simulated registrar: deterministic availability, TLD prices from the provider
 * settings, idempotent registrations stored in the mock resource store.
 * Nothing is bought, no external API is called.
 */
final readonly class MockDomainProvider implements DomainProviderInterface
{
    /** Labels considered already registered (for demos and tests). */
    private const TAKEN_LABELS = ['google', 'facebook', 'amazon', 'microsoft', 'mzian', 'example'];

    public function __construct(
        private MockResourceStore $store,
        private MockBehavior $behavior,
    ) {
    }

    public static function getDriver(): string
    {
        return 'mock';
    }

    public function checkAvailability(Provider $provider, string $domain): DomainAvailability
    {
        $domain = RequirementService::normalizeDomain($domain);
        if (!RequirementService::isValidDomain($domain)) {
            throw ProviderException::permanent(\sprintf('"%s" is not a valid domain name.', $domain), $provider->getCode());
        }
        $label = explode('.', $domain)[0];
        $taken = \in_array($label, self::TAKEN_LABELS, true) || str_contains($label, 'taken')
            || null !== $this->store->findByPayload($provider->getCode(), 'domain', ['domain' => $domain]);

        return new DomainAvailability($domain, !$taken, $this->price($provider, $domain), $this->currency($provider));
    }

    public function tldPrice(Provider $provider, string $tld): int
    {
        return $this->price($provider, 'name.'.ltrim($tld, '.'));
    }

    public function register(Provider $provider, string $domain, int $years, string $idempotencyKey): DomainRegistration
    {
        $existing = $this->store->find($provider->getCode(), 'domain', $idempotencyKey);
        if (null !== $existing) {
            return $this->toRegistration($existing->getPayload(), $existing->getExternalId());
        }
        $this->behavior->simulate($provider, 'register', $idempotencyKey);

        $availability = $this->checkAvailability($provider, $domain);
        if (!$availability->available) {
            throw ProviderException::permanent(\sprintf('The domain %s is not available.', $availability->domain), $provider->getCode());
        }
        $payload = [
            'domain' => $availability->domain,
            'cost' => $availability->price * $years,
            'currency' => $availability->currency,
            'expires_at' => (new \DateTimeImmutable(\sprintf('+%d years', $years)))->format(\DATE_ATOM),
            'nameservers' => (array) ($provider->getSettings()['nameservers'] ?? ['ns1.mock-registrar.test', 'ns2.mock-registrar.test']),
        ];
        $resource = $this->store->save($provider->getCode(), 'domain', $idempotencyKey, 'mockdom_'.substr(hash('sha256', $idempotencyKey), 0, 12), $payload);

        return $this->toRegistration($payload, $resource->getExternalId());
    }

    public function configureDns(Provider $provider, string $domain, array $records, string $idempotencyKey): void
    {
        if (null !== $this->store->find($provider->getCode(), 'dns', $idempotencyKey)) {
            return;
        }
        $this->behavior->simulate($provider, 'configure_dns', $idempotencyKey);
        $this->store->save($provider->getCode(), 'dns', $idempotencyKey, 'mockdns_'.substr(hash('sha256', $idempotencyKey), 0, 12), ['domain' => $domain, 'records' => $records]);
    }

    private function currency(Provider $provider): string
    {
        return (string) ($provider->getSettings()['currency'] ?? 'USD');
    }

    private function price(Provider $provider, string $domain): int
    {
        $prices = (array) ($provider->getSettings()['tld_prices'] ?? []);
        $tld = '.'.substr($domain, (int) strrpos($domain, '.') + 1);

        return (int) round((float) ($prices[$tld] ?? $prices['default'] ?? 15) * 100);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toRegistration(array $payload, string $externalId): DomainRegistration
    {
        return new DomainRegistration(
            (string) $payload['domain'],
            $externalId,
            (int) $payload['cost'],
            (string) $payload['currency'],
            new \DateTimeImmutable((string) $payload['expires_at']),
            array_values(array_map('strval', (array) $payload['nameservers'])),
            true,
        );
    }
}
