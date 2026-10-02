<?php

declare(strict_types=1);

namespace App\Tests\Integration\Deployment;

use App\Deployment\Dto\DeploymentRequest;
use App\Deployment\Provider\LocalDeploymentProvider;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Exception\ProviderException;
use App\Provider\Mock\MockBehavior;
use App\Provider\Mock\MockResourceStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalDeploymentProviderTest extends KernelTestCase
{
    private string $source;
    private string $deployments;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->deployments = (string) static::getContainer()->getParameter('mzian.deployments_dir');
        $this->source = sys_get_temp_dir().'/mzian-deploy-src-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->dumpFile($this->source.'/public/index.html', '<h1>v1</h1>');
        $fs->dumpFile($this->source.'/app/schema.json', '{}');
        $fs->dumpFile($this->source.'/.git/HEAD', 'ref');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->source, $this->deployments.'/atlas-test']);
        parent::tearDown();
    }

    private function deployer(): LocalDeploymentProvider
    {
        $store = new MockResourceStore(static::getContainer()->get(EntityManagerInterface::class));

        return new LocalDeploymentProvider($this->deployments, 'http://localhost:8081', 'http://apps:8081', new MockBehavior($store, 0));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function request(string $version, array $config = ['admin_email' => 'a@b.c', 'admin_password_hash' => 'hash']): DeploymentRequest
    {
        return new DeploymentRequest('atlas-test', $this->source, $version, 'atlas.com', 'deploy-'.$version, null, $config);
    }

    public function testReleaseSwitchConfigAndIdempotency(): void
    {
        $provider = new Provider('local_t', ProviderType::Deployment, 'local', 'Local', ProvisioningMethod::Mock);
        $result = $this->deployer()->deploy($provider, $this->request('abc123'));

        self::assertSame('http://localhost:8081/atlas-test/', $result->url);
        self::assertSame('http://apps:8081/atlas-test/', $result->internalUrl);
        self::assertSame('http://localhost:8081/atlas-test/admin/', $result->adminUrl);
        self::assertTrue($result->simulated);
        self::assertSame('abc123', file_get_contents($this->deployments.'/atlas-test/current'));
        self::assertFileExists($this->deployments.'/atlas-test/releases/abc123/public/index.html');
        self::assertDirectoryDoesNotExist($this->deployments.'/atlas-test/releases/abc123/.git', 'The git metadata is never deployed.');
        $config = require $this->deployments.'/atlas-test/releases/abc123/app/config.php';
        self::assertSame('../../../data', $config['data_dir'], 'Data is kept outside the release, across deployments.');
        self::assertStringNotContainsString('admin_password_hash', print_r($this->request('x'), true));

        $again = $this->deployer()->deploy($provider, $this->request('abc123'));
        self::assertContains('Release abc123 already uploaded (idempotent).', $again->logs);

        file_put_contents($this->source.'/public/index.html', '<h1>v2</h1>');
        $this->deployer()->deploy($provider, $this->request('def456'));
        self::assertSame('def456', file_get_contents($this->deployments.'/atlas-test/current'));
        self::assertDirectoryExists($this->deployments.'/atlas-test/releases/abc123', 'Previous release kept for rollback.');
    }

    public function testUnsafeSlugIsRefused(): void
    {
        $this->expectException(ProviderException::class);
        $this->deployer()->deploy(new Provider('local_t', ProviderType::Deployment, 'local', 'Local', ProvisioningMethod::Mock), new DeploymentRequest('../etc', $this->source, 'v1', null, 'k'));
    }
}
