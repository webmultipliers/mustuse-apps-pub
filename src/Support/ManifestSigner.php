<?php

declare(strict_types=1);

namespace MustUse\Pub\Support;

/**
 * Signs manifests so shells can verify integrity.
 *
 * HMAC-SHA256 over the exact byte sequence WP_REST_Server will emit.
 * Both sides use `wp_json_encode($manifest)` with default flags, so
 * the bytes we sign match the bytes the shell receives.
 *
 * Rotate the signing key via `rotateKey()` when a leaked shell binary
 * needs invalidating.
 */
final class ManifestSigner
{
    private const OPTION_KEY = 'mua_manifest_signing_key';

    /**
     * @param array<string, mixed> $manifest
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function sign(array $manifest): array
    {
        $body = wp_json_encode($manifest);
        if ($body === false) {
            $body = '{}';
        }
        $signature = \hash_hmac('sha256', $body, self::getOrCreateKey());
        return [$manifest, $signature];
    }

    /**
     * Sign an already-serialized manifest body. Used at build-projection
     * time where BuildAssembler needs the signature to match the exact
     * JSON bytes it writes to disk.
     */
    public static function signRaw(string $body): string
    {
        return \hash_hmac('sha256', $body, self::getOrCreateKey());
    }

    public static function getOrCreateKey(): string
    {
        $key = get_option(self::OPTION_KEY);

        if (! $key) {
            $key = \bin2hex(\random_bytes(32));
            update_option(self::OPTION_KEY, $key, false);
        }

        return $key;
    }

    public static function rotateKey(): string
    {
        $key = \bin2hex(\random_bytes(32));
        update_option(self::OPTION_KEY, $key, false);

        return $key;
    }
}
