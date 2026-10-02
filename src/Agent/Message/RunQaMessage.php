<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * QA agent: audits the deployed application over HTTP.
 */
final class RunQaMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'run_qa';
    }
}
