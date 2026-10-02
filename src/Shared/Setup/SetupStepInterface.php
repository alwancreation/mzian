<?php

declare(strict_types=1);

namespace App\Shared\Setup;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An idempotent step of `bin/console mzian:setup` (safe to run on every deploy).
 */
#[AutoconfigureTag('mzian.setup_step')]
interface SetupStepInterface
{
    /** Lower runs first. */
    public static function getPriority(): int;

    public function run(SymfonyStyle $io, bool $demo): void;
}
