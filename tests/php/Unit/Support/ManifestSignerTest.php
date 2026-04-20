<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Support\ManifestSigner;
use PHPUnit\Framework\TestCase;

final class ManifestSignerTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias(static fn ($value, $flags = 0) => \json_encode($value, $flags));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_sign_returns_manifest_and_hex_signature(): void
    {
        Functions\when('get_option')->justReturn('deadbeef');
        Functions\when('update_option')->justReturn(true);

        [$returnedManifest, $signature] = ManifestSigner::sign(['version' => 1, 'app' => ['id' => 1]]);

        self::assertSame(['version' => 1, 'app' => ['id' => 1]], $returnedManifest);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    public function test_sign_is_deterministic_for_same_key_and_body(): void
    {
        Functions\when('get_option')->justReturn('stable-key');
        Functions\when('update_option')->justReturn(true);

        [, $a] = ManifestSigner::sign(['v' => 1]);
        [, $b] = ManifestSigner::sign(['v' => 1]);

        self::assertSame($a, $b);
    }

    public function test_sign_changes_when_body_changes(): void
    {
        Functions\when('get_option')->justReturn('stable-key');
        Functions\when('update_option')->justReturn(true);

        [, $a] = ManifestSigner::sign(['v' => 1]);
        [, $b] = ManifestSigner::sign(['v' => 2]);

        self::assertNotSame($a, $b);
    }

    public function test_signRaw_returns_matching_hmac_of_body_bytes(): void
    {
        Functions\when('get_option')->justReturn('shared-test-key');
        Functions\when('update_option')->justReturn(true);

        $body = '{"exact":"bytes","order":true}';
        $signature = ManifestSigner::signRaw($body);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
        self::assertSame(
            \hash_hmac('sha256', $body, 'shared-test-key'),
            $signature,
            'signRaw must HMAC the exact input bytes — shell verifies by hmacing file_get_contents output.',
        );
    }

    public function test_getOrCreateKey_generates_when_missing(): void
    {
        $stored = null;
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->alias(static function ($k, $v) use (&$stored) {
            $stored = $v;
            return true;
        });

        $key = ManifestSigner::getOrCreateKey();

        self::assertSame($stored, $key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    public function test_rotateKey_replaces_and_returns_new_key(): void
    {
        $stored = 'old-key';
        Functions\when('get_option')->alias(static function () use (&$stored) {
            return $stored;
        });
        Functions\when('update_option')->alias(static function ($k, $v) use (&$stored) {
            $stored = $v;
            return true;
        });

        $new = ManifestSigner::rotateKey();

        self::assertNotSame('old-key', $new);
        self::assertSame($new, $stored);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $new);
    }
}
