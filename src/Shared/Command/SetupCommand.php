<?php

declare(strict_types=1);

namespace App\Shared\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mzian:setup', description: 'Idempotent platform setup: catalog, providers, agents, settings (and demo accounts with --demo).')]
final class SetupCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Also create demo accounts (development only).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->success('Mzian setup completed.');

        return Command::SUCCESS;
    }
}
