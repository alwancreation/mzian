<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * Testing agent: runs the automated tests on the generated application.
 */
final class RunTestsMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'run_tests';
    }
}
