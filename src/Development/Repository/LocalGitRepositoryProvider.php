<?php

declare(strict_types=1);

namespace App\Development\Repository;

use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Real Git repositories stored on the platform server (bare repositories under
 * var/repositories). Works offline: the default for development, and a backup
 * option in production. Clone with: git clone <path>.
 */
final readonly class LocalGitRepositoryProvider implements RepositoryProviderInterface
{
    private const AUTHOR = ['GIT_AUTHOR_NAME' => 'Mzian Development Agent', 'GIT_AUTHOR_EMAIL' => 'agents@mzian.net', 'GIT_COMMITTER_NAME' => 'Mzian Development Agent', 'GIT_COMMITTER_EMAIL' => 'agents@mzian.net'];

    public function __construct(
        #[Autowire('%mzian.repositories_dir%')]
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public static function getDriver(): string
    {
        return 'local_git';
    }

    public function createRepository(Provider $provider, string $name, string $description): RepositoryInfo
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{1,99}$/', $name)) {
            throw ProviderException::permanent(\sprintf('Invalid repository name "%s".', $name), $provider->getCode());
        }
        $path = $this->directory.'/'.$name.'.git';
        if (!is_dir($path)) {
            $this->filesystem->mkdir($path);
            $this->git(['init', '--bare', '--initial-branch=main', $path], $provider);
            file_put_contents($path.'/description', $description."\n");
        }

        return new RepositoryInfo($name, 'local/'.$name, $path, $path, 'main', true);
    }

    public function commit(Provider $provider, RepositoryInfo $repository, string $sourceDir, string $message): CommitInfo
    {
        if (!is_dir($repository->cloneUrl)) {
            throw ProviderException::permanent('Repository not found: '.$repository->cloneUrl, $provider->getCode());
        }
        $work = sys_get_temp_dir().'/mzian-git-'.bin2hex(random_bytes(6));
        try {
            $this->git(['clone', '--quiet', $repository->cloneUrl, $work], $provider);
            $this->git(['-C', $work, 'checkout', '--quiet', '-B', $repository->defaultBranch], $provider);
            // Replace the working tree with the generated sources (deleted files are removed too).
            foreach ((new Finder())->in($work)->depth(0)->ignoreDotFiles(false)->notName('.git') as $entry) {
                $this->filesystem->remove($entry->getPathname());
            }
            $this->filesystem->mirror($sourceDir, $work);
            $this->git(['-C', $work, 'add', '--all'], $provider);
            $hasHead = $this->git(['-C', $work, 'rev-parse', '--verify', '--quiet', 'HEAD'], $provider, false)->isSuccessful();
            $changes = trim($this->git(['-C', $work, 'status', '--porcelain'], $provider)->getOutput());
            if ($hasHead && '' === $changes) {
                return new CommitInfo(trim($this->git(['-C', $work, 'rev-parse', 'HEAD'], $provider)->getOutput()), null, false);
            }
            $this->git(['-C', $work, 'commit', '--quiet', '-m', $message], $provider);
            $this->git(['-C', $work, 'push', '--quiet', 'origin', 'HEAD:'.$repository->defaultBranch], $provider);

            return new CommitInfo(trim($this->git(['-C', $work, 'rev-parse', 'HEAD'], $provider)->getOutput()), null, true);
        } finally {
            $this->filesystem->remove($work);
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, Provider $provider, bool $mustSucceed = true): Process
    {
        $process = new Process(['git', '-c', 'safe.directory=*', '-c', 'init.defaultBranch=main', ...$arguments], null, self::AUTHOR + ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(120);
        $process->run();
        if ($mustSucceed && !$process->isSuccessful()) {
            throw ProviderException::transient(\sprintf('git %s failed: %s', $arguments[0], trim($process->getErrorOutput())), $provider->getCode());
        }

        return $process;
    }
}
