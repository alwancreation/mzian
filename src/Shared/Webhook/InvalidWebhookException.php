<?php

declare(strict_types=1);

namespace App\Shared\Webhook;

/**
 * The webhook could not be authenticated (missing/invalid signature, replay) or parsed.
 * Nothing in the payload is trusted or processed.
 */
final class InvalidWebhookException extends \RuntimeException
{
}
