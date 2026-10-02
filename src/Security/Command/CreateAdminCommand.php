<?php

declare(strict_types=1);

namespace App\Security\Command;

use App\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mzian:user:create-admin', description: 'Create a Mzian administrator account.')]
final class CreateAdminCommand extends Command
{
    public function __construct(private readonly UserManager $userManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('name', InputArgument::REQUIRED, 'Display name')
            ->addOption('super', null, InputOption::VALUE_NONE, 'Grant ROLE_SUPER_ADMIN (providers, credentials, settings)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (asked interactively when omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $password = $input->getOption('password') ?? $io->askHidden('Password (min. 12 characters)');
        if (!\is_string($password) || mb_strlen($password) < 12) {
            $io->error('The password must contain at least 12 characters.');

            return Command::INVALID;
        }

        try {
            $admin = $this->userManager->createAdmin((string) $input->getArgument('email'), $password, (string) $input->getArgument('name'), (bool) $input->getOption('super'));
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Administrator %s created.', $admin->getUser()->getEmail()));

        return Command::SUCCESS;
    }
}
