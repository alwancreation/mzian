<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\AgentPermission;
use App\Agent\Entity\Agent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentPermissionTest extends TestCase
{
    /**
     * @return iterable<array{string}>
     */
    public static function humanDecisions(): iterable
    {
        foreach (AgentPermission::FORBIDDEN as $permission) {
            yield $permission => [$permission];
        }
    }

    #[DataProvider('humanDecisions')]
    public function testHumanDecisionsCanNeverBeGrantedToAnAgent(string $permission): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Agent('rogue', 'Rogue', 'x', [AgentPermission::TESTS_RUN, $permission]);
    }

    public function testUnknownPermissionsAreRefusedAndUpdatesAreChecked(): void
    {
        $agent = new Agent('qa', 'QA', 'x', [AgentPermission::QA_RUN]);
        self::assertTrue($agent->isAllowed(AgentPermission::QA_RUN));
        self::assertFalse($agent->isAllowed(AgentPermission::BUDGET_SPEND));

        $this->expectException(\InvalidArgumentException::class);
        $agent->update('QA', 'x', ['project.approve'], 3);
    }
}
