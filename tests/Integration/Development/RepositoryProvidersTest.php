<?php

declare(strict_types=1);

namespace App\Tests\Integration\Development;

use App\Development\Repository\GitHubRepositoryProvider;
use App\Development\Repository\LocalGitRepositoryProvider;
use App\Development\Repository\RepositoryInfo;
use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Exception\ProviderException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class RepositoryProvidersTest extends KernelTestCase
{
    private string $work;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->work = sys_get_temp_dir().'/mzian-repo-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->work.'/src');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->work, ((string) static::getContainer()->getParameter('mzian.repositories_dir')).'/mzian-client-test.git']);
        unset($_SERVER['MZIAN_TEST_GH_TOKEN']);
        parent::tearDown();
    }

    private function git(): LocalGitRepositoryProvider
    {
        return new LocalGitRepositoryProvider((string) static::getContainer()->getParameter('mzian.repositories_dir'));
    }

    private function provider(string $driver): Provider
    {
        return new Provider($driver.'_t', ProviderType::Repository, $driver, $driver, ProvisioningMethod::Api);
    }

    public function testLocalGitCreatesRealRepositoriesAndCommitsIdempotently(): void
    {
        $git = $this->git();
        $provider = $this->provider('local_git');

        $repository = $git->createRepository($provider, 'mzian-client-test', 'Atlas Cars');
        self::assertSame($repository->cloneUrl, $git->createRepository($provider, 'mzian-client-test', 'Atlas Cars')->cloneUrl, 'Creating twice returns the same repository.');

        file_put_contents($this->work.'/src/index.html', '<h1>v1</h1>');
        mkdir($this->work.'/src/assets');
        file_put_contents($this->work.'/src/assets/app.js', 'console.log(1);');
        $first = $git->commit($provider, $repository, $this->work.'/src', 'Initial version');
        self::assertTrue($first->created);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $first->sha);

        $same = $git->commit($provider, $repository, $this->work.'/src', 'Retry');
        self::assertFalse($same->created, 'Unchanged sources: no new commit on retry.');
        self::assertSame($first->sha, $same->sha);

        unlink($this->work.'/src/assets/app.js');
        file_put_contents($this->work.'/src/index.html', '<h1>v2</h1>');
        $second = $git->commit($provider, $repository, $this->work.'/src', 'Update');
        self::assertTrue($second->created);

        $clone = $this->work.'/clone';
        (new Process(['git', '-c', 'safe.directory=*', 'clone', '--quiet', $repository->cloneUrl, $clone]))->mustRun();
        self::assertSame('<h1>v2</h1>', file_get_contents($clone.'/index.html'));
        self::assertFileDoesNotExist($clone.'/assets/app.js', 'Deleted files are removed from the repository.');
        $log = (new Process(['git', '-c', 'safe.directory=*', '-C', $clone, 'log', '--format=%an|%s']))->mustRun()->getOutput();
        self::assertSame("Mzian Development Agent|Update\nMzian Development Agent|Initial version\n", $log);
    }

    public function testLocalGitRejectsUnsafeNames(): void
    {
        $this->expectException(ProviderException::class);
        $this->git()->createRepository($this->provider('local_git'), '../../etc', 'x');
    }

    public function testGitHubCreatesThePrivateRepositoryAndPushesATree(): void
    {
        $_SERVER['MZIAN_TEST_GH_TOKEN'] = 'github_pat_test';
        $calls = [];
        $responses = [
            'GET /repos/mzian-clients/mzian-client-42' => new MockResponse('{"message":"Not Found"}', ['http_code' => 404]),
            'POST /orgs/mzian-clients/repos' => new MockResponse('{"full_name":"mzian-clients/mzian-client-42","html_url":"https://github.com/mzian-clients/mzian-client-42","clone_url":"https://github.com/mzian-clients/mzian-client-42.git","default_branch":"main"}', ['http_code' => 201]),
            'GET /repos/mzian-clients/mzian-client-42/git/ref/heads/main' => new MockResponse('{"object":{"sha":"base"}}'),
            'GET /repos/mzian-clients/mzian-client-42/git/commits/base' => new MockResponse('{"tree":{"sha":"tree-old"},"html_url":"x"}'),
            'POST /repos/mzian-clients/mzian-client-42/git/trees' => new MockResponse('{"sha":"tree-new"}', ['http_code' => 201]),
            'POST /repos/mzian-clients/mzian-client-42/git/commits' => new MockResponse('{"sha":"c0ffee","html_url":"https://github.com/c"}', ['http_code' => 201]),
            'PATCH /repos/mzian-clients/mzian-client-42/git/refs/heads/main' => new MockResponse('{}'),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, $responses) {
            $key = $method.' '.parse_url($url, \PHP_URL_PATH);
            $calls[$key] = ['body' => isset($options['body']) ? json_decode((string) $options['body'], true) : null, 'headers' => $options['headers']];

            return $responses[$key] ?? new MockResponse('{"message":"unexpected '.$key.'"}', ['http_code' => 500]);
        });
        $provider = new Provider('github_t', ProviderType::Repository, 'github', 'GitHub', ProvisioningMethod::Api);
        $provider->setSettings(['organization' => 'mzian-clients', 'env' => ['token' => 'MZIAN_TEST_GH_TOKEN']]);
        $github = new GitHubRepositoryProvider($http, static::getContainer()->get(CredentialVault::class));

        $repository = $github->createRepository($provider, 'mzian-client-42', 'Atlas Cars');
        self::assertSame('mzian-clients/mzian-client-42', $repository->fullName);
        self::assertTrue($calls['POST /orgs/mzian-clients/repos']['body']['private']);
        self::assertContains('Authorization: Bearer github_pat_test', $calls['POST /orgs/mzian-clients/repos']['headers']);

        file_put_contents($this->work.'/src/index.html', '<h1>Hi</h1>');
        $commit = $github->commit($provider, $repository, $this->work.'/src', 'Generated by Mzian');
        self::assertSame('c0ffee', $commit->sha);
        self::assertSame([['path' => 'index.html', 'mode' => '100644', 'type' => 'blob', 'content' => '<h1>Hi</h1>']], $calls['POST /repos/mzian-clients/mzian-client-42/git/trees']['body']['tree']);
        self::assertSame(['sha' => 'c0ffee', 'force' => false], $calls['PATCH /repos/mzian-clients/mzian-client-42/git/refs/heads/main']['body']);
    }

    public function testGitHubWithoutTokenIsNotConfigured(): void
    {
        $github = new GitHubRepositoryProvider(new MockHttpClient(), static::getContainer()->get(CredentialVault::class));
        $provider = new Provider('github_t', ProviderType::Repository, 'github', 'GitHub', ProvisioningMethod::Api);
        $provider->setSettings(['organization' => 'org', 'env' => ['token' => 'MZIAN_TEST_UNSET_TOKEN']]);

        $this->expectException(ProviderException::class);
        $github->commit($provider, new RepositoryInfo('r', 'org/r', 'u', 'c'), $this->work.'/src', 'x');
    }
}
