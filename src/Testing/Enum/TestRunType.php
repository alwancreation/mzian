<?php

declare(strict_types=1);

namespace App\Testing\Enum;

enum TestRunType: string
{
    /** Automated test-suite run on the generated application (before deployment). */
    case Automated = 'automated';
    /** QA checks executed against the deployed application. */
    case Qa = 'qa';
}
