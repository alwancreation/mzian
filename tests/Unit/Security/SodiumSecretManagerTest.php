<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\SodiumSecretManager;
use PHPUnit\Framework\TestCase;

final class SodiumSecretManagerTest extends TestCase
{
    private function manager(?string $key = null): SodiumSecretManager
    {
        return new SodiumSecretManager($key ?? base64_encode(str_repeat('k', 32)));
    }

    public function testRoundTripAndRandomNonce(): void
    {
        $manager = $this->manager();
        $a = $manager->encrypt('sk_live_123');
        $b = $manager->encrypt('sk_live_123');

        self::assertNotSame($a, $b, 'Each encryption uses a fresh nonce.');
        self::assertStringNotContainsString('sk_live_123', $a);
        self::assertSame('sk_live_123', $manager->decrypt($a));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $manager = $this->manager();
        $cipher = $manager->encrypt('secret');
        $raw = base64_decode(substr($cipher, 3), true);
        $raw[30] = \chr(\ord($raw[30]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $manager->decrypt('v1:'.base64_encode($raw));
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $cipher = $this->manager()->encrypt('secret');

        $this->expectException(\RuntimeException::class);
        $this->manager(base64_encode(str_repeat('x', 32)))->decrypt($cipher);
    }

    public function testInvalidKeyIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager('too-short')->encrypt('x');
    }
}
