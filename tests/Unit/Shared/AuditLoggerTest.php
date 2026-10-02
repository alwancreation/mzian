<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Project\Enum\ProjectStatus;
use App\Shared\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testSecretsAreMaskedRecursively(): void
    {
        $sanitized = AuditLogger::sanitize([
            'email' => 'a@b.c',
            'password' => 'hunter2',
            'provider' => ['api_key' => 'sk_123', 'region' => 'eu'],
            'accessToken' => 'abc',
            'status' => ProjectStatus::Approved,
            'at' => new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ]);

        self::assertSame('a@b.c', $sanitized['email']);
        self::assertSame('***', $sanitized['password']);
        self::assertSame(['api_key' => '***', 'region' => 'eu'], $sanitized['provider']);
        self::assertSame('***', $sanitized['accessToken']);
        self::assertSame('APPROVED', $sanitized['status']);
        self::assertSame('2026-01-01T00:00:00+00:00', $sanitized['at']);
    }
}
