<?php

declare(strict_types=1);

namespace App\Hosting\Provider;

use App\Hosting\Dto\HostingProvisioning;
use App\Hosting\Dto\HostingSite;
use App\Hosting\Entity\HostingAccount;
use App\Hosting\Entity\HostingPlan;
use App\Project\Entity\Project;
use App\Provider\Entity\Provider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Hosting company driver (cPanel/Plesk resellers, Hostinger, OVH, o2switch, Mock...).
 * Every call is idempotent for a given key: a retry never creates a second
 * account or site (and never pays twice). See PROVIDERS.md to add one.
 */
#[AutoconfigureTag('mzian.hosting_provider')]
interface HostingProviderInterface
{
    public static function getDriver(): string;

    /**
     * @throws \App\Provider\Exception\ProviderException
     */
    public function provisionAccount(Provider $provider, HostingPlan $plan, Project $project, string $idempotencyKey): HostingProvisioning;

    /**
     * Creates the website on the account (document root, PHP, database, SSL).
     *
     * @param array<string, mixed> $requirements solution hosting requirements (database, ssl...)
     *
     * @throws \App\Provider\Exception\ProviderException
     */
    public function createSite(Provider $provider, HostingAccount $account, string $domain, array $requirements, string $idempotencyKey): HostingSite;
}
