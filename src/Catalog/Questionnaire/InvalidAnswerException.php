<?php

declare(strict_types=1);

namespace App\Catalog\Questionnaire;

final class InvalidAnswerException extends \InvalidArgumentException
{
    public function __construct(public readonly string $translationKey, string $message = '')
    {
        parent::__construct('' !== $message ? $message : $translationKey);
    }
}
