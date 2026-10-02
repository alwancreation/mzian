<?php

declare(strict_types=1);

namespace App\Security\Command;

use App\Security\RuntimeSecretsChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Run by the container entrypoint and the deployment workflow: in production the
 * platform does not start with missing or well-known secrets.
 */
#[AsCommand(name: 'mzian:security:check', description: 'Checks that the runtime secrets are set and are not the committed development values.')]
final class SecurityCheckCommand extends Command
{
    public function __construct(
        private readonly RuntimeSecretsChecker $checker,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on problems whatever the environment (default: only in prod).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problems = $this->checker->problems();
        if ([] === $problems) {
            $io->success('Runtime secrets are set and are not development values.');

            return Command::SUCCESS;
        }
        $fail = 'prod' === $this->environment || (bool) $input->getOption('strict');
        $io->{$fail ? 'error' : 'warning'}(array_merge([$fail ? 'Insecure configuration: the platform refuses to run.' : 'Development secrets in use (fine locally, refused in production):'], $problems));

        return $fail ? Command::FAILURE : Command::SUCCESS;
    }
}
