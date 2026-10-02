<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * Development agent: creates the Git repository of the customer project.
 */
final class CreateProjectMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'create_project';
    }
}
