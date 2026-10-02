<?php

declare(strict_types=1);

namespace App\Tests\Unit\Testing;

use App\Project\Entity\Project;
use App\Requirement\Entity\Requirement;
use App\Testing\Entity\TestResult;
use App\Testing\Entity\TestRun;
use App\Testing\Enum\TestRunType;
use App\Testing\Enum\TestSeverity;
use App\Testing\Enum\TestStatus;
use PHPUnit\Framework\TestCase;

final class TestRunTest extends TestCase
{
    private function newRun(): TestRun
    {
        $project = new Project('PRJ-TEST', 'mzian-client-test', 'Test', new Requirement());

        return new TestRun($project, TestRunType::Qa, 'http://example.test');
    }

    public function testAllPassedGivesFullScore(): void
    {
        $run = $this->newRun();
        new TestResult($run, 'http', 'Homepage', 'qa', TestSeverity::Critical, TestStatus::Passed, 'ok');
        new TestResult($run, 'assets', 'Assets', 'qa', TestSeverity::Major, TestStatus::Passed, 'ok');
        $run->finish();

        self::assertTrue($run->isPassed());
        self::assertSame(100, $run->getScore());
        self::assertSame([], $run->toReport()['critical_errors']);
    }

    public function testCriticalFailureFailsTheRunWhateverTheScore(): void
    {
        $run = $this->newRun();
        new TestResult($run, 'http', 'Homepage', 'qa', TestSeverity::Critical, TestStatus::Failed, 'HTTP 500');
        for ($i = 0; $i < 10; ++$i) {
            new TestResult($run, 'ok'.$i, 'Check '.$i, 'qa', TestSeverity::Minor, TestStatus::Passed, 'ok');
        }
        $run->finish();

        self::assertFalse($run->isPassed());
        self::assertSame('failed', $run->toReport()['status']);
        self::assertSame(['Homepage: HTTP 500'], $run->toReport()['critical_errors']);
    }

    public function testSkippedChecksAreExcludedAndNonCriticalFailuresAreWarnings(): void
    {
        $run = $this->newRun();
        new TestResult($run, 'http', 'Homepage', 'qa', TestSeverity::Critical, TestStatus::Passed, 'ok');
        new TestResult($run, 'https', 'HTTPS', 'qa', TestSeverity::Critical, TestStatus::Skipped, 'simulated deployment');
        new TestResult($run, 'responsive', 'Responsive', 'qa', TestSeverity::Major, TestStatus::Failed, 'no viewport');
        $run->finish();

        self::assertTrue($run->isPassed());
        self::assertSame(60, $run->getScore()); // 3 of 5 weighted points
        self::assertCount(1, $run->toReport()['warnings']);
        self::assertSame(1, $run->toReport()['skipped']);
    }

    public function testAnEmptyRunIsNeverAPass(): void
    {
        $run = $this->newRun();
        $run->finish();

        self::assertFalse($run->isPassed(), 'No executed check means nothing was verified.');
    }
}
