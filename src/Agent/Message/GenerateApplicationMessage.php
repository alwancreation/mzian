<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * Development agent: generates the application from its template and commits it.
 */
final class GenerateApplicationMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'generate_application';
    }
}
