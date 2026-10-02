<?php

declare(strict_types=1);

namespace App\Development\Repository;

final readonly class RepositoryInfo
{
    public function __construct(
        public string $name,
        public string $fullName,
        /** Where a human can browse it (web URL or local path). */
        public string $url,
        public string $cloneUrl,
        public string $defaultBranch = 'main',
        public bool $local = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['name'], (string) $data['fullName'], (string) $data['url'], (string) $data['cloneUrl'], (string) ($data['defaultBranch'] ?? 'main'), (bool) ($data['local'] ?? false));
    }
}
