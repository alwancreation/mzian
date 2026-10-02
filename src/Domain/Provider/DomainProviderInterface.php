<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Dto\DomainAvailability;
use App\Domain\Dto\DomainRegistration;
use App\Provider\Entity\Provider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Registrar driver (Namecheap, OVH, Cloudflare Registrar, Mock...).
 * Every mutating call receives an idempotency key: calling it twice with the same
 * key must never register or charge twice. See PROVIDERS.md to add a registrar.
 */
#[AutoconfigureTag('mzian.domain_provider')]
interface DomainProviderInterface
{
    public static function getDriver(): string;

    /**
     * @throws \App\Provider\Exception\ProviderException
     */
    public function checkAvailability(Provider $provider, string $domain): DomainAvailability;

    /**
     * Yearly registration price of a TLD (".com"), minor units; null when unknown.
     * Used for estimates (pricing page) without checking a specific name.
     *
     * @throws \App\Provider\Exception\ProviderException
     */
    public function tldPrice(Provider $provider, string $tld): ?int;

    /**
     * @throws \App\Provider\Exception\ProviderException
     */
    public function register(Provider $provider, string $domain, int $years, string $idempotencyKey): DomainRegistration;

    /**
     * Points the domain to the hosting (A / CNAME records...).
     *
     * @param list<array{type: string, name: string, value: string, ttl?: int}> $records
     *
     * @throws \App\Provider\Exception\ProviderException
     */
    public function configureDns(Provider $provider, string $domain, array $records, string $idempotencyKey): void;
}
