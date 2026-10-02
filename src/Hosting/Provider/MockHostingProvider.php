<?php

declare(strict_types=1);

namespace App\Hosting\Provider;

use App\Hosting\Dto\HostingProvisioning;
use App\Hosting\Dto\HostingSite;
use App\Hosting\Entity\HostingAccount;
use App\Hosting\Entity\HostingPlan;
use App\Project\Entity\Project;
use App\Provider\Entity\Provider;
use App\Provider\Mock\MockBehavior;
use App\Provider\Mock\MockResourceStore;

/**
 * Simulated hosting company: idempotent accounts and sites stored in the mock
 * resource store, plan prices from the catalog, nothing bought, no external call.
 * Generated passwords are returned once (like real providers) and never stored here.
 */
final readonly class MockHostingProvider implements HostingProviderInterface
{
    public function __construct(
        private MockResourceStore $store,
        private MockBehavior $behavior,
    ) {
    }

    public static function getDriver(): string
    {
        return 'mock';
    }

    public function provisionAccount(Provider $provider, HostingPlan $plan, Project $project, string $idempotencyKey): HostingProvisioning
    {
        $existing = $this->store->find($provider->getCode(), 'hosting_account', $idempotencyKey);
        if (null === $existing) {
            $this->behavior->simulate($provider, 'create_account', $idempotencyKey);
            $hash = substr(hash('sha256', $idempotencyKey), 0, 10);
            $existing = $this->store->save($provider->getCode(), 'hosting_account', $idempotencyKey, 'mockhost_'.$hash, [
                'username' => 'mz'.substr($hash, 0, 8),
                'region' => (string) ($provider->getSettings()['region'] ?? 'eu-simulated'),
                'ip' => '10.20.'.(hexdec(substr($hash, 0, 2)) % 250).'.'.(hexdec(substr($hash, 2, 2)) % 250 + 2),
                'panel' => rtrim((string) ($provider->getSettings()['panel_url'] ?? 'https://panel.mock-hosting.test'), '/').'/'.$hash,
                'plan' => $plan->getCode(),
                'cost' => $plan->getYearlyPrice(),
                'currency' => $plan->getCurrency(),
                'expires_at' => (new \DateTimeImmutable('+1 year'))->format(\DATE_ATOM),
            ]);
            $password = bin2hex(random_bytes(12));
        }
        $payload = $existing->getPayload();

        return new HostingProvisioning(
            $existing->getExternalId(),
            (string) $payload['username'],
            (string) $payload['region'],
            (string) $payload['ip'],
            (string) $payload['panel'],
            new \DateTimeImmutable((string) $payload['expires_at']),
            (int) $payload['cost'],
            (string) $payload['currency'],
            true,
            $password ?? null,
        );
    }

    public function createSite(Provider $provider, HostingAccount $account, string $domain, array $requirements, string $idempotencyKey): HostingSite
    {
        $existing = $this->store->find($provider->getCode(), 'hosting_site', $idempotencyKey);
        if (null === $existing) {
            $this->behavior->simulate($provider, 'create_site', $idempotencyKey);
            $database = (bool) ($requirements['database'] ?? false);
            $existing = $this->store->save($provider->getCode(), 'hosting_site', $idempotencyKey, 'mocksite_'.substr(hash('sha256', $idempotencyKey), 0, 10), [
                'document_root' => '/home/'.$account->getUsername().'/'.$domain.'/public',
                'php' => '8.3',
                'database' => $database ? substr((string) $account->getUsername(), 0, 8).'_app' : null,
                'ssl' => (bool) ($requirements['ssl'] ?? true),
            ]);
            $password = $database ? bin2hex(random_bytes(12)) : null;
        }
        $payload = $existing->getPayload();

        return new HostingSite(
            $existing->getExternalId(),
            (string) $payload['document_root'],
            (string) $payload['php'],
            isset($payload['database']) ? (string) $payload['database'] : null,
            isset($payload['database']) ? (string) $payload['database'] : null,
            (bool) $payload['ssl'],
            $password ?? null,
        );
    }
}
