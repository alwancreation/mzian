<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Boolean = 'boolean';
    case Number = 'number';
    case Text = 'text';
}
