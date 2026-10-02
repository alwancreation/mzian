<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\RuntimeSecretsChecker;
use PHPUnit\Framework\TestCase;

final class RuntimeSecretsCheckerTest extends TestCase
{
    public function testTheCommittedDevelopmentAndTestValuesAreRefused(): void
    {
        foreach (['.env.dev', '.env.test'] as $file) {
            $values = parse_ini_file(\dirname(__DIR__, 3).'/'.$file, false, \INI_SCANNER_RAW) ?: [];
            $checker = new RuntimeSecretsChecker((string) ($values['APP_SECRET'] ?? ''), (string) ($values['MZIAN_ENCRYPTION_KEY'] ?? ''), (string) ($values['MZIAN_WEBHOOK_SECRET'] ?? ''));
            self::assertCount(3, $checker->problems(), $file);
        }
    }

    public function testMissingOrMalformedSecretsAreRefused(): void
    {
        self::assertCount(3, (new RuntimeSecretsChecker('', '', ''))->problems());
        $problems = (new RuntimeSecretsChecker(bin2hex(random_bytes(32)), base64_encode('too-short'), bin2hex(random_bytes(16))))->problems();
        self::assertCount(1, $problems);
        self::assertStringContainsString('MZIAN_ENCRYPTION_KEY', $problems[0]);
    }

    public function testRandomSecretsAreAccepted(): void
    {
        $checker = new RuntimeSecretsChecker(bin2hex(random_bytes(32)), base64_encode(random_bytes(32)), bin2hex(random_bytes(24)));
        self::assertSame([], $checker->problems());
    }

    public function testProblemsNeverContainTheSecretValues(): void
    {
        $checker = new RuntimeSecretsChecker('dev-only-short', 'dev-only-key', 'dev-only-hook');
        self::assertStringNotContainsString('dev-only', implode(' ', $checker->problems()));
    }
}
