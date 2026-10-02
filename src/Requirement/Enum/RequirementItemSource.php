<?php

declare(strict_types=1);

namespace App\Requirement\Enum;

enum RequirementItemSource: string
{
    case Questionnaire = 'questionnaire';
    case Conversation = 'conversation';
    case Ai = 'ai';
    case Admin = 'admin';
}
