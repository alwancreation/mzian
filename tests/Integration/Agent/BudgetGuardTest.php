<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Budget\BudgetGuard;
use App\Agent\Exception\NeedsAdminException;
use App\Project\Enum\CostCategory;
use App\Tests\Support\Factory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BudgetGuardTest extends KernelTestCase
{
    private BudgetGuard $guard;
    private \App\Project\Entity\Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->guard = static::getContainer()->get(BudgetGuard::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->project = (new Factory($em, static::getContainer()->get(UserPasswordHasherInterface::class)))->project();
        $this->project->setBudget(10000);
    }

    private function blocked(CostCategory $category, int $amount, bool $recurring = false): ?string
    {
        try {
            $this->guard->authorize($this->project, $category, $amount, 'Test spending', $recurring);

            return null;
        } catch (NeedsAdminException $e) {
            self::assertSame(['category' => $category->value, 'amount' => $amount, 'description' => 'Test spending'], $e->metadata['spending']);

            return $e->getMessage();
        }
    }

    public function testAutomationPolicyLimits(): void
    {
        self::assertNull($this->blocked(CostCategory::Hosting, 7200), 'Within the $100 hosting limit.');
        self::assertStringContainsString('above the automatic limit', (string) $this->blocked(CostCategory::Hosting, 12000));
        self::assertNull($this->blocked(CostCategory::Domain, 1200));
        self::assertStringContainsString('above the automatic limit', (string) $this->blocked(CostCategory::Domain, 4000), '$30 domain limit.');
        self::assertStringContainsString('above the automatic limit', (string) $this->blocked(CostCategory::Hosting, 2900 + 200, true), 'Recurring costs use the monthly limit.');
        self::assertStringContainsString('administrator approval required', (string) $this->blocked(CostCategory::Ai, 15000));
    }

    public function testProjectBudgetAndAdministratorOverride(): void
    {
        $this->guard->record($this->project, CostCategory::Hosting, 9000, 'USD', 'Hosting', 'ext-1', 'key-1', true);
        $this->guard->record($this->project, CostCategory::Hosting, 9000, 'USD', 'Hosting (retry)', 'ext-1', 'key-1', true);
        self::assertSame(9000, $this->project->getSpent(), 'Idempotent: the retry is not counted twice.');

        self::assertStringContainsString('exceed the project budget', (string) $this->blocked(CostCategory::Domain, 2000));
        self::assertNull($this->blocked(CostCategory::Domain, 1000));

        $this->project->approveSpending(CostCategory::Domain, 2500);
        self::assertNull($this->blocked(CostCategory::Domain, 2500), 'Approved by an administrator.');
        self::assertNotNull($this->blocked(CostCategory::Domain, 2600), 'Only up to the approved amount.');
    }
}
