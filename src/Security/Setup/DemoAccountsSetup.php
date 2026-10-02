<?php

declare(strict_types=1);

namespace App\Security\Setup;

use App\Security\Repository\UserRepository;
use App\Security\UserManager;
use App\Shared\Setup\SetupStepInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Development only: a super administrator and a customer to try the whole workflow.
 * The credentials are documented in the README and refused in production.
 */
final readonly class DemoAccountsSetup implements SetupStepInterface
{
    public const ADMIN_EMAIL = 'admin@mzian.test';
    public const ADMIN_PASSWORD = 'admin-demo-1234';
    public const CUSTOMER_EMAIL = 'client@mzian.test';
    public const CUSTOMER_PASSWORD = 'client-demo-1234';

    public function __construct(
        private UserRepository $users,
        private UserManager $userManager,
    ) {
    }

    public static function getPriority(): int
    {
        return 900;
    }

    public function run(SymfonyStyle $io, bool $demo): void
    {
        if (!$demo) {
            return;
        }
        if (null === $this->users->findOneBy(['email' => self::ADMIN_EMAIL])) {
            $this->userManager->createAdmin(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'Demo Admin', true);
            // Passwords are never written to the output (container logs): they are documented in the README.
            $io->writeln(\sprintf('  Demo super admin: <info>%s</info> (password: see README)', self::ADMIN_EMAIL));
        }
        if (null === $this->users->findOneBy(['email' => self::CUSTOMER_EMAIL])) {
            $this->userManager->createCustomer(self::CUSTOMER_EMAIL, self::CUSTOMER_PASSWORD, 'Yasmine', 'Demo', 'Atlas Cars', '+212 600 000 000');
            $io->writeln(\sprintf('  Demo customer: <info>%s</info> (password: see README)', self::CUSTOMER_EMAIL));
        }
    }
}
