<?php

declare(strict_types=1);

namespace App\Deployment\Provider;

use App\Deployment\Dto\DeploymentRequest;
use App\Deployment\Dto\DeploymentResult;
use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderException;
use App\Provider\Mock\MockBehavior;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Real deployment on the platform's "apps" server (docker compose service "apps",
 * docker/apps/router.php): releases in var/deployments/<slug>/releases/<version>,
 * atomic switch of the "current" pointer, data kept between releases, last 5
 * releases kept for rollback. The application really runs (website + admin), but
 * on a preview address instead of the customer's domain: deployments are flagged
 * as simulated.
 *
 * Settings: public_base_url, internal_base_url (default MZIAN_APPS_* env).
 */
final readonly class LocalDeploymentProvider implements DeploymentProviderInterface
{
    private const KEEP_RELEASES = 5;

    public function __construct(
        #[Autowire('%mzian.deployments_dir%')]
        private string $directory,
        #[Autowire('%env(MZIAN_APPS_PUBLIC_URL)%')]
        private string $publicBaseUrl,
        #[Autowire('%env(MZIAN_APPS_INTERNAL_URL)%')]
        private string $internalBaseUrl,
        private MockBehavior $behavior,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public static function getDriver(): string
    {
        return 'local';
    }

    public function deploy(Provider $provider, DeploymentRequest $request): DeploymentResult
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/', $request->slug) || 1 !== preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $request->version)) {
            throw ProviderException::permanent('Invalid deployment slug or version.', $provider->getCode());
        }
        if (!is_dir($request->sourceDir.'/public')) {
            throw ProviderException::permanent('Nothing to deploy: '.$request->sourceDir.'/public is missing.', $provider->getCode());
        }
        $this->behavior->simulate($provider, 'deploy', $request->idempotencyKey);

        $base = $this->directory.'/'.$request->slug;
        $release = $base.'/releases/'.$request->version;
        $logs = [];
        if (is_dir($release)) {
            $logs[] = 'Release '.$request->version.' already uploaded (idempotent).';
        } else {
            $tmp = $release.'.tmp-'.bin2hex(random_bytes(3));
            $this->filesystem->mirror($request->sourceDir, $tmp, null, ['override' => true]);
            $this->filesystem->remove([$tmp.'/.git', $tmp.'/app/data']);
            $this->filesystem->rename($tmp, $release);
            $logs[] = 'Uploaded release '.$request->version.'.';
        }
        $this->filesystem->mkdir($base.'/data', 0o770);
        if ([] !== $request->runtimeConfig()) {
            $this->filesystem->dumpFile($release.'/app/config.php', '<?php return '.var_export($request->runtimeConfig() + ['data_dir' => '../../../data'], true).";\n");
            $logs[] = 'Runtime configuration written (app/config.php, not in the repository).';
        }
        $this->filesystem->dumpFile($base.'/current', $request->version);
        $logs[] = 'Switched "current" to '.$request->version.'.';
        $logs[] = $this->cleanup($base.'/releases', $request->version);

        $settings = $provider->getSettings();
        $public = rtrim((string) ($settings['public_base_url'] ?? $this->publicBaseUrl), '/').'/'.$request->slug.'/';
        $internal = rtrim((string) ($settings['internal_base_url'] ?? $this->internalBaseUrl), '/').'/'.$request->slug.'/';

        return new DeploymentResult($public, $internal, $public.'admin/', 'local:'.$request->slug.'@'.$request->version, array_values(array_filter($logs)), true);
    }

    private function cleanup(string $releases, string $current): string
    {
        $all = glob($releases.'/*', \GLOB_ONLYDIR) ?: [];
        usort($all, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        $removed = 0;
        foreach (\array_slice($all, self::KEEP_RELEASES) as $old) {
            if (basename($old) !== $current) {
                $this->filesystem->remove($old);
                ++$removed;
            }
        }

        return $removed > 0 ? \sprintf('Removed %d old release(s).', $removed) : '';
    }
}
