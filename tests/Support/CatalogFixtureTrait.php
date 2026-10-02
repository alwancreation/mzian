<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\Import\CatalogImporter;

/**
 * Imports the real catalog (config/mzian/*.yaml) inside the test transaction.
 */
trait CatalogFixtureTrait
{
    protected function importCatalog(): void
    {
        static::getContainer()->get(CatalogImporter::class)->import();
    }
}
