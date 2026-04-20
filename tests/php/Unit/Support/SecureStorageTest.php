<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Support\SecureStorage;
use PHPUnit\Framework\TestCase;

final class SecureStorageTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();

        if (! \defined('MUA_SECURE_STORAGE_KEY')) {
            \define('MUA_SECURE_STORAGE_KEY', 'test-key-for-phpunit-only-never-use-in-prod');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_encrypt_produces_v1_prefix(): void
    {
        Functions\stubs(['get_option' => false, 'update_option' => true]);

        $cipher = SecureStorage::encrypt('hello world');

        self::assertStringStartsWith('v1.', $cipher);
    }

    public function test_encrypt_is_non_deterministic(): void
    {
        Functions\stubs(['get_option' => false, 'update_option' => true]);

        $a = SecureStorage::encrypt('same plaintext');
        $b = SecureStorage::encrypt('same plaintext');

        self::assertNotSame($a, $b, 'IV must randomize each encryption.');
    }

    public function test_roundtrip_recovers_plaintext(): void
    {
        Functions\stubs(['get_option' => false, 'update_option' => true]);

        $plaintext = 'APNs-token::abc.def.ghi';
        $cipher = SecureStorage::encrypt($plaintext);

        self::assertSame($plaintext, SecureStorage::decrypt($cipher));
    }

    public function test_decrypt_returns_null_for_missing_prefix(): void
    {
        Functions\stubs(['get_option' => false, 'update_option' => true]);

        self::assertNull(SecureStorage::decrypt('not-a-payload'));
    }

    public function test_decrypt_returns_null_for_short_payload(): void
    {
        Functions\stubs(['get_option' => false, 'update_option' => true]);

        self::assertNull(SecureStorage::decrypt('v1.' . \base64_encode('short')));
    }

    public function test_decrypt_returns_null_on_tampered_ciphertext(): void
    {
        Functions\stubs(['get_option' => false, 'update_option' => true]);

        $cipher = SecureStorage::encrypt('sensitive');

        $raw = \base64_decode(\substr($cipher, 3), true);
        self::assertNotFalse($raw);
        $tampered = 'v1.' . \base64_encode(\substr($raw, 0, -1) . \chr(\ord($raw[-1]) ^ 0x01));

        self::assertNull(SecureStorage::decrypt($tampered));
    }
}
