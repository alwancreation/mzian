<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Guards the "every status change goes through the state machine" rule.
 */
final class StateMachineIntegrityTest extends TestCase
{
    public function testProjectStatusIsOnlyChangedByTheWorkflow(): void
    {
        $offenders = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
            $code = $file->getContents();
            if (str_ends_with($file->getRelativePathname(), 'Project/Entity/Project.php')) {
                continue;
            }
            if (preg_match('/->setStatus\(\s*ProjectStatus::/', $code) || preg_match('/->status\s*=\s*ProjectStatus::/', $code)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $offenders, 'Use ProjectStateMachine::apply() instead of changing the project status directly.');
    }
}
