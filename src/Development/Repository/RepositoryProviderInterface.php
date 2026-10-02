<?php

declare(strict_types=1);

namespace App\Development\Repository;

use App\Provider\Entity\Provider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source code hosting for the generated applications (one repository per
 * customer project, e.g. "mzian-client-42"). Both operations are idempotent.
 */
#[AutoconfigureTag('mzian.repository_provider')]
interface RepositoryProviderInterface
{
    public static function getDriver(): string;

    /**
     * Creates the private repository, or returns it when it already exists.
     *
     * @throws \App\Provider\Exception\ProviderException
     */
    public function createRepository(Provider $provider, string $name, string $description): RepositoryInfo;

    /**
     * Commits the whole content of $sourceDir on the default branch (no-op if unchanged).
     *
     * @throws \App\Provider\Exception\ProviderException
     */
    public function commit(Provider $provider, RepositoryInfo $repository, string $sourceDir, string $message): CommitInfo;
}
