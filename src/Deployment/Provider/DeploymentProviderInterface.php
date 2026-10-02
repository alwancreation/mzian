<?php

declare(strict_types=1);

namespace App\Deployment\Provider;

use App\Deployment\Dto\DeploymentRequest;
use App\Deployment\Dto\DeploymentResult;
use App\Provider\Entity\Provider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Puts a generated application online (local preview server, SFTP/FTP, hosting
 * API, PaaS...). Deploying the same version twice must not create a second
 * release. See PROVIDERS.md to add one.
 */
#[AutoconfigureTag('mzian.deployment_provider')]
interface DeploymentProviderInterface
{
    public static function getDriver(): string;

    /**
     * @throws \App\Provider\Exception\ProviderException
     */
    public function deploy(Provider $provider, DeploymentRequest $request): DeploymentResult;
}
