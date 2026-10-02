<?php

declare(strict_types=1);

namespace App\Development\Repository;

use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderException;
use App\Provider\Exception\ProviderNotConfiguredException;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GitHub repositories through the REST API (fine-grained token with
 * "Administration" + "Contents" write on the organization: least privilege).
 *
 * Settings: organization (or env GITHUB_ORGANIZATION; empty = the token's user),
 * private (default true). Credential: token (GITHUB_TOKEN).
 */
final readonly class GitHubRepositoryProvider implements RepositoryProviderInterface
{
    private const MAX_FILE_BYTES = 1_000_000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CredentialVault $vault,
    ) {
    }

    public static function getDriver(): string
    {
        return 'github';
    }

    public function createRepository(Provider $provider, string $name, string $description): RepositoryInfo
    {
        $owner = $this->owner($provider);
        [$status, $data] = $this->call($provider, 'GET', '/repos/'.$owner.'/'.$name, null, [200, 404]);
        if (404 === $status) {
            $path = $this->organization($provider) ? '/orgs/'.$owner.'/repos' : '/user/repos';
            [, $data] = $this->call($provider, 'POST', $path, [
                'name' => $name,
                'description' => mb_substr($description, 0, 350),
                'private' => (bool) ($provider->getSettings()['private'] ?? true),
                'auto_init' => true,
                'has_wiki' => false,
            ], [201]);
        }

        return new RepositoryInfo($name, (string) $data['full_name'], (string) $data['html_url'], (string) $data['clone_url'], (string) ($data['default_branch'] ?? 'main'));
    }

    public function commit(Provider $provider, RepositoryInfo $repository, string $sourceDir, string $message): CommitInfo
    {
        $repo = '/repos/'.$repository->fullName;
        [, $ref] = $this->call($provider, 'GET', $repo.'/git/ref/heads/'.$repository->defaultBranch, null, [200]);
        $parent = (string) $ref['object']['sha'];
        [, $parentCommit] = $this->call($provider, 'GET', $repo.'/git/commits/'.$parent, null, [200]);

        $tree = [];
        foreach ((new Finder())->files()->in($sourceDir)->ignoreDotFiles(false)->sortByName() as $file) {
            $content = $file->getContents();
            if (\strlen($content) > self::MAX_FILE_BYTES) {
                throw ProviderException::permanent('File too large for the repository: '.$file->getRelativePathname(), $provider->getCode());
            }
            $entry = ['path' => str_replace('\\', '/', $file->getRelativePathname()), 'mode' => '100644', 'type' => 'blob'];
            if (mb_check_encoding($content, 'UTF-8')) {
                $entry['content'] = $content;
            } else {
                [, $blob] = $this->call($provider, 'POST', $repo.'/git/blobs', ['content' => base64_encode($content), 'encoding' => 'base64'], [201]);
                $entry['sha'] = (string) $blob['sha'];
            }
            $tree[] = $entry;
        }
        // Full tree without base_tree: files removed from the build are removed from the repository.
        [, $newTree] = $this->call($provider, 'POST', $repo.'/git/trees', ['tree' => $tree], [201]);
        if ($newTree['sha'] === ($parentCommit['tree']['sha'] ?? null)) {
            return new CommitInfo($parent, (string) ($parentCommit['html_url'] ?? ''), false);
        }
        [, $commit] = $this->call($provider, 'POST', $repo.'/git/commits', ['message' => $message, 'tree' => $newTree['sha'], 'parents' => [$parent]], [201]);
        $this->call($provider, 'PATCH', $repo.'/git/refs/heads/'.$repository->defaultBranch, ['sha' => $commit['sha'], 'force' => false], [200]);

        return new CommitInfo((string) $commit['sha'], (string) ($commit['html_url'] ?? ''), true);
    }

    private function organization(Provider $provider): ?string
    {
        $organization = (string) ($provider->getSettings()['organization'] ?? '');
        if ('' === $organization) {
            $organization = (string) ($this->vault->get($provider, 'organization') ?? '');
        }

        return '' !== $organization ? $organization : null;
    }

    private function owner(Provider $provider): string
    {
        $organization = $this->organization($provider);
        if (null !== $organization) {
            return $organization;
        }
        [, $user] = $this->call($provider, 'GET', '/user', null, [200]);

        return (string) $user['login'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<int>                 $expected
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(Provider $provider, string $method, string $path, ?array $body, array $expected): array
    {
        $token = $this->vault->get($provider, 'token') ?? throw new ProviderNotConfiguredException('GitHub token is missing (Admin > Providers or GITHUB_TOKEN).', $provider->getCode());
        $baseUrl = rtrim((string) ($provider->getSettings()['base_url'] ?? 'https://api.github.com'), '/');
        try {
            $response = $this->httpClient->request($method, $baseUrl.$path, array_filter([
                'auth_bearer' => $token,
                'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Mzian.net'],
                'json' => $body,
                'timeout' => 30,
            ], static fn ($v) => null !== $v));
            $status = $response->getStatusCode();
            $data = '' !== $response->getContent(false) ? $response->toArray(false) : [];
        } catch (ExceptionInterface $e) {
            throw ProviderException::transient('GitHub unreachable: '.$e->getMessage(), $provider->getCode(), $e);
        }
        if (!\in_array($status, $expected, true)) {
            $message = \sprintf('GitHub %s %s returned %d: %s', $method, $path, $status, (string) ($data['message'] ?? ''));
            throw $status >= 500 || 429 === $status || 403 === $status && str_contains(strtolower((string) ($data['message'] ?? '')), 'rate limit') ? ProviderException::transient($message, $provider->getCode()) : ProviderException::permanent($message, $provider->getCode());
        }

        return [$status, $data];
    }
}
