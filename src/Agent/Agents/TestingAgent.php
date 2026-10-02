<?php

declare(strict_types=1);

namespace App\Agent\Agents;

use App\Agent\AgentContext;
use App\Agent\AgentInterface;
use App\Agent\AgentPermission;
use App\Agent\AgentResult;
use App\Agent\Exception\AgentException;
use App\Project\Enum\PipelineStep;
use App\Testing\Check\CheckResult;
use App\Testing\Check\SmokeTester;
use App\Testing\Check\StaticApplicationChecks;
use App\Testing\Entity\TestResult;
use App\Testing\Entity\TestRun;
use App\Testing\Enum\TestRunType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Testing Agent: runs the automated tests on the generated sources (static
 * analysis + the application started and driven over HTTP) and records every
 * result. A critical failure blocks the deployment: the project never reaches
 * DELIVERY with failing critical tests.
 */
final readonly class TestingAgent implements AgentInterface
{
    public function __construct(
        private StaticApplicationChecks $staticChecks,
        private SmokeTester $smoke,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getCode(): string
    {
        return 'testing';
    }

    public static function step(): PipelineStep
    {
        return PipelineStep::Testing;
    }

    public static function requiredPermissions(string $operation): array
    {
        return [AgentPermission::TESTS_RUN];
    }

    public function execute(AgentContext $context): AgentResult
    {
        $development = $context->outputOf(PipelineStep::Development);
        $directory = (string) ($development['build_dir'] ?? '');
        if ('' === $directory || !is_dir($directory)) {
            throw AgentException::permanent('No generated application to test.');
        }

        $run = new TestRun($context->project, TestRunType::Automated, 'version '.($development['version'] ?? '?'));
        foreach ([...$this->staticChecks->run($directory), ...$this->smoke->run($directory, $context->project->getSlug())] as $check) {
            /* @var CheckResult $check */
            new TestResult($run, $check->code, $check->name, $check->category, $check->severity, $check->status, $check->message, $check->durationMs, $check->details);
        }
        $run->finish();
        $this->em->persist($run);
        $this->em->flush();
        $report = $run->toReport();
        $context->log('Automated tests finished', $report);

        if (!$run->isPassed()) {
            throw AgentException::transient('Critical tests failed: '.implode('; ', $report['critical_errors']), ['test_run' => $run->getId()]);
        }

        return new AgentResult(\sprintf('%d checks executed, score %d/100.', $report['checks'], (int) $report['score']), ['test_run' => $run->getId(), 'report' => $report]);
    }
}
