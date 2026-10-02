<?php

declare(strict_types=1);

namespace App\Development\Repository;

final readonly class CommitInfo
{
    public function __construct(
        public string $sha,
        public ?string $url,
        /** False when the tree was identical (idempotent retry): no new commit. */
        public bool $created,
    ) {
    }
}
