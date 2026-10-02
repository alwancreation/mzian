<?php

declare(strict_types=1);

namespace App\Agent\Agents;

use App\Agent\AgentContext;
use App\Agent\AgentInterface;
use App\Agent\AgentPermission;
use App\Agent\AgentResult;
use App\Agent\Budget\BudgetGuard;
use App\Agent\Exception\AgentException;
use App\Project\Enum\PipelineStep;
use App\Testing\Check\QaChecker;
use App\Testing\Entity\TestResult;
use App\Testing\Entity\TestRun;
use App\Testing\Enum\TestRunType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * QA Agent: audits the deployed application over HTTP. The project only goes to
 * delivery when no critical check failed and the score reaches the QA threshold
 * (Admin > Settings). Report: {status, score, critical_errors, warnings}.
 */
final readonly class QaAgent implements AgentInterface
{
    public function __construct(
        private QaChecker $checker,
        private BudgetGuard $budget,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getCode(): string
    {
        return 'qa';
    }

    public static function step(): PipelineStep
    {
        return PipelineStep::Qa;
    }

    public static function requiredPermissions(string $operation): array
    {
        return [AgentPermission::QA_RUN];
    }

    public function execute(AgentContext $context): AgentResult
    {
        $project = $context->project;
        $deployment = $context->outputOf(PipelineStep::Deployment);
        $manifest = json_decode((string) @file_get_contents(($context->outputOf(PipelineStep::Development)['build_dir'] ?? '').'/mzian.json'), true);
        if (!isset($deployment['url'], $deployment['internal_url']) || !\is_array($manifest)) {
            throw AgentException::permanent('Nothing deployed to check.');
        }

        $run = new TestRun($project, TestRunType::Qa, (string) $deployment['url']);
        $checks = $this->checker->run((string) $deployment['url'], (string) $deployment['internal_url'], $manifest, (string) ($project->getBusinessName() ?? $project->getName()), (bool) ($deployment['simulated'] ?? true), $project->getDomainName());
        foreach ($checks as $check) {
            new TestResult($run, $check->code, $check->name, $check->category, $check->severity, $check->status, $check->message, $check->durationMs, $check->details);
        }
        $run->finish();
        $this->em->persist($run);
        $this->em->flush();
        $report = $run->toReport();
        $minimum = $this->budget->policy()->qaMinScore;
        $context->log('QA finished', $report + ['minimum_score' => $minimum]);

        if (!$run->isPassed() || (int) $report['score'] < $minimum) {
            throw AgentException::transient(\sprintf('QA not passed (score %d/100, minimum %d): %s', (int) $report['score'], $minimum, implode('; ', $report['critical_errors']) ?: implode('; ', $report['warnings'])), ['test_run' => $run->getId()]);
        }

        return new AgentResult(\sprintf('QA passed: %d/100.', (int) $report['score']), ['test_run' => $run->getId(), 'report' => $report]);
    }
}
