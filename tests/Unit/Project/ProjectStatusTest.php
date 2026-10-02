<?php

declare(strict_types=1);

namespace App\Tests\Unit\Project;

use App\Project\Enum\PipelineStep;
use App\Project\Enum\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class ProjectStatusTest extends TestCase
{
    public function testAutomationStatesStartAtApproval(): void
    {
        self::assertFalse(ProjectStatus::PendingAdminApproval->isAutomation());
        self::assertFalse(ProjectStatus::Paid->isAutomation());
        self::assertTrue(ProjectStatus::Approved->isAutomation());
        self::assertTrue(ProjectStatus::Delivery->isAutomation());
        self::assertFalse(ProjectStatus::Completed->isAutomation());
    }

    public function testProgressIsMonotonicAlongTheHappyPath(): void
    {
        $path = [
            ProjectStatus::Draft, ProjectStatus::Analyzing, ProjectStatus::Quoted, ProjectStatus::Ordered, ProjectStatus::Paid,
            ProjectStatus::PendingAdminApproval, ProjectStatus::Approved, ProjectStatus::Provisioning, ProjectStatus::HostingReady,
            ProjectStatus::DomainReady, ProjectStatus::Development, ProjectStatus::Testing, ProjectStatus::Deploying,
            ProjectStatus::Deployed, ProjectStatus::Qa, ProjectStatus::Delivery, ProjectStatus::Completed,
        ];
        $previous = -1;
        foreach ($path as $status) {
            self::assertGreaterThan($previous, $status->progress(), $status->value);
            $previous = $status->progress();
        }
        self::assertSame(100, ProjectStatus::Completed->progress());
    }

    public function testTerminalStates(): void
    {
        self::assertTrue(ProjectStatus::Completed->isTerminal());
        self::assertTrue(ProjectStatus::Cancelled->isTerminal());
        self::assertFalse(ProjectStatus::Failed->isTerminal(), 'A failed project can be retried by an admin.');
    }

    public function testPipelineStepsAreOrderedAndChained(): void
    {
        $steps = PipelineStep::ordered();
        self::assertSame(PipelineStep::Hosting, $steps[0]);
        self::assertSame(PipelineStep::Delivery, end($steps));
        self::assertSame(PipelineStep::Domain, PipelineStep::Hosting->next());
        self::assertNull(PipelineStep::Delivery->next());

        // QA must run after the deployment: it checks the deployed application.
        self::assertGreaterThan(PipelineStep::Deployment->position(), PipelineStep::Qa->position());
    }

    public function testQualityGatesCannotBeSkipped(): void
    {
        self::assertFalse(PipelineStep::Testing->isSkippable());
        self::assertFalse(PipelineStep::Qa->isSkippable());
        self::assertTrue(PipelineStep::Domain->isSkippable());
    }

    public function testEachStepStartsWhereThePreviousOneEnds(): void
    {
        foreach (PipelineStep::ordered() as $step) {
            $next = $step->next();
            if (null === $next) {
                continue;
            }
            $endStatus = match ($step->completeTransition()) {
                'hosting_ready' => ProjectStatus::HostingReady,
                'domain_ready' => ProjectStatus::DomainReady,
                'deployed' => ProjectStatus::Deployed,
                default => $step->runningStatus(),
            };
            self::assertSame($endStatus, $next->requiredStatus(), \sprintf('%s -> %s', $step->value, $next->value));
        }
    }
}
