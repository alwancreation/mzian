<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Setup\SetupStepInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(name: 'mzian:setup', description: 'Idempotent platform setup: catalog, providers, agents, settings (and demo accounts with --demo).')]
final class SetupCommand extends Command
{
    /**
     * @param iterable<SetupStepInterface> $steps
     */
    public function __construct(
        #[AutowireIterator('mzian.setup_step', defaultPriorityMethod: 'getPriority')]
        private readonly iterable $steps,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Also create demo accounts (refused in the prod environment).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $demo = (bool) $input->getOption('demo');
        if ($demo && 'prod' === $this->environment) {
            $io->error('Demo accounts cannot be created in the prod environment.');

            return Command::FAILURE;
        }

        $steps = iterator_to_array($this->steps, false);
        usort($steps, static fn (SetupStepInterface $a, SetupStepInterface $b) => $a::getPriority() <=> $b::getPriority());
        foreach ($steps as $step) {
            $step->run($io, $demo);
        }

        $io->success('Mzian setup completed.');

        return Command::SUCCESS;
    }
}
