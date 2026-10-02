<?php

declare(strict_types=1);

namespace App\Provider\Exception;

final class ProviderNotConfiguredException extends ProviderException
{
    public function __construct(string $message, ?string $provider = null)
    {
        parent::__construct($message, false, $provider);
    }
}
