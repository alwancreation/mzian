<?php

declare(strict_types=1);

namespace App\Shared\Webhook;

/**
 * Webhook signatures (HMAC-SHA256). Every webhook endpoint verifies them before
 * reading the payload; comparisons are constant-time.
 *
 *  - timestamped scheme (Stripe and Mzian mock/generic providers):
 *      header "t=<unix time>,v1=<hex hmac of "<t>.<raw body>">", replay window = tolerance
 *  - GitHub scheme: header "sha256=<hex hmac of raw body>"
 */
final class WebhookSignature
{
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret));
    }

    public static function verify(string $payload, ?string $header, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        if ('' === $secret || null === $header || '' === $header) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ('t' === $key && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ('v1' === $key && '' !== $value) {
                $signatures[] = $value;
            }
        }
        if (null === $timestamp || [] === $signatures || abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function verifyGithub(string $payload, ?string $header, string $secret): bool
    {
        if ('' === $secret || null === $header || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $header);
    }
}
