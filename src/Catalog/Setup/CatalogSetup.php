<?php

declare(strict_types=1);

namespace App\Catalog\Setup;

use App\Catalog\Import\CatalogImporter;
use App\Shared\Setup\SetupStepInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class CatalogSetup implements SetupStepInterface
{
    public function __construct(private CatalogImporter $importer)
    {
    }

    public static function getPriority(): int
    {
        return 100;
    }

    public function run(SymfonyStyle $io, bool $demo): void
    {
        $stats = $this->importer->import();
        $io->writeln(\sprintf('  Catalog: +%d sectors, +%d solutions, +%d features, +%d questions, +%d plans', $stats['sectors'], $stats['solutions'], $stats['features'], $stats['questions'], $stats['plans']));
    }
}
