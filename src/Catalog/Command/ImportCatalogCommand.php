<?php

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\CatalogImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mzian:catalog:import', description: 'Import config/mzian/catalog.yaml and questionnaire.yaml into the database.')]
final class ImportCatalogCommand extends Command
{
    public function __construct(private readonly CatalogImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('update', null, InputOption::VALUE_NONE, 'Overwrite existing items with the YAML values (admin edits are lost).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->importer->import((bool) $input->getOption('update'));
        (new SymfonyStyle($input, $output))->success(\sprintf('Imported: %s', json_encode($stats)));

        return Command::SUCCESS;
    }
}
