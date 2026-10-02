<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Webhook\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function testValidSignatureIsAccepted(): void
    {
        $header = WebhookSignature::sign('{"id":"evt_1"}', 'secret', 1_700_000_000);

        self::assertTrue(WebhookSignature::verify('{"id":"evt_1"}', $header, 'secret', 300, 1_700_000_100));
    }

    public function testTamperedPayloadWrongSecretReplayAndMissingHeaderAreRejected(): void
    {
        $header = WebhookSignature::sign('{"amount":100}', 'secret', 1_700_000_000);

        self::assertFalse(WebhookSignature::verify('{"amount":1}', $header, 'secret', 300, 1_700_000_000), 'tampered payload');
        self::assertFalse(WebhookSignature::verify('{"amount":100}', $header, 'other', 300, 1_700_000_000), 'wrong secret');
        self::assertFalse(WebhookSignature::verify('{"amount":100}', $header, 'secret', 300, 1_700_000_301), 'replayed too late');
        self::assertFalse(WebhookSignature::verify('{"amount":100}', null, 'secret'), 'missing header');
        self::assertFalse(WebhookSignature::verify('{"amount":100}', $header, ''), 'no secret configured');
        self::assertFalse(WebhookSignature::verify('{"amount":100}', 't=abc,v1=xyz', 'secret'), 'garbage header');
    }

    public function testStripeStyleHeaderWithSeveralSignatures(): void
    {
        $payload = '{"id":"evt_2"}';
        $valid = hash_hmac('sha256', '1700000000.'.$payload, 'whsec');

        self::assertTrue(WebhookSignature::verify($payload, 't=1700000000,v1=deadbeef,v1='.$valid.',v0=old', 'whsec', 300, 1_700_000_000));
    }

    public function testGithubSignature(): void
    {
        $payload = '{"action":"opened"}';

        self::assertTrue(WebhookSignature::verifyGithub($payload, 'sha256='.hash_hmac('sha256', $payload, 'gh'), 'gh'));
        self::assertFalse(WebhookSignature::verifyGithub($payload, 'sha1=abc', 'gh'));
        self::assertFalse(WebhookSignature::verifyGithub($payload.' ', 'sha256='.hash_hmac('sha256', $payload, 'gh'), 'gh'));
    }
}
