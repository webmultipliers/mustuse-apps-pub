<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\WellKnown;

/**
 * Per-IP, per-minute request limiter for unauthenticated well-known
 * endpoints. Transient-backed — deliberately cheap, not a production
 * rate-limiter replacement (edge/CDN is the right layer) but enough
 * to absorb accidental bursts and tooling misconfigurations.
 */
final class WellKnownThrottle
{
    public static function allow(string $bucket, string $remoteAddress, int $perMinute): bool
    {
        $ip = self::normalize($remoteAddress);
        $minute = (int) \floor(\time() / 60);
        $key = 'mua_wk_' . \hash('sha256', $bucket . '|' . $ip . '|' . $minute);

        $count = (int) get_transient($key);
        if ($count >= $perMinute) {
            return false;
        }

        set_transient($key, $count + 1, 120);
        return true;
    }

    private static function normalize(string $ip): string
    {
        $ip = \trim($ip);
        if ($ip === '') {
            return 'unknown';
        }
        return \filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'invalid';
    }
}
