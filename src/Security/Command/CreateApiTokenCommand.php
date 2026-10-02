<?php

declare(strict_types=1);

namespace App\Security\Command;

use App\Security\Repository\UserRepository;
use App\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mzian:api-token:create', description: 'Create an API token (Bearer) for a user. The token is displayed once.')]
final class CreateApiTokenCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token name', 'cli')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Validity in days (0 = no expiry)', '90');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneBy(['email' => mb_strtolower((string) $input->getArgument('email'))]);
        if (null === $user) {
            $io->error('User not found.');

            return Command::FAILURE;
        }
        $days = (int) $input->getOption('days');
        [, $clear] = $this->userManager->createApiToken($user, (string) $input->getOption('name'), $days > 0 ? new \DateTimeImmutable("+{$days} days") : null);

        $io->success('Token created. Store it now, it will not be displayed again:');
        $io->writeln($clear);

        return Command::SUCCESS;
    }
}
