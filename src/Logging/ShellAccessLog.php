<?php

declare(strict_types=1);

namespace MustUse\Pub\Logging;

/**
 * Local debugging log for pub-shell API requests.
 *
 * Context is redacted so credentials, signatures, and bearer tokens
 * can't leak into error_log even if a handler accidentally passes a
 * raw request.
 */
final class ShellAccessLog
{
    /**
     * Context keys that must never be written to the log in cleartext.
     * Matched against the lowercased key name (full match).
     */
    private const SENSITIVE_KEYS = [
        'credential',
        'signature',
        'secret',
        'token',
        'authorization',
        'api_key',
        'apikey',
        'password',
        'artifact_url',
        'pat',
        'bearer',
        'client_secret',
        'private_key',
        'keystore',
        'team_id',
        'apple_team_id',
        'fingerprint',
        'github_token',
    ];

    /**
     * Underscore-delimited tokens that, when present as a whole word in a
     * key name, mark the value as sensitive. Catches compound names like
     * `github_pat`, `fcm_server_key`. Whole-token matching avoids false
     * positives like `path` matching `pat`.
     */
    private const SENSITIVE_TOKENS = [
        'token', 'secret', 'password', 'pat', 'key',
        'signature', 'credential', 'bearer', 'keystore',
        'fingerprint',
    ];

    public static function record(string $event, array $context = []): void
    {
        if (! \defined('WP_DEBUG_LOG') || ! WP_DEBUG_LOG) {
            return;
        }

        \error_log(\sprintf(
            '[MUA Shell Access] %s | %s | %s',
            current_time('c'),
            $event,
            wp_json_encode(self::redact($context))
        ));
    }

    /**
     * Replace sensitive values with "[redacted]" while preserving keys,
     * so logs still record *that* something was set without leaking what.
     * Applied recursively.
     */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (\is_array($value)) {
                $context[$key] = self::redact($value);
                continue;
            }
            if (\is_string($key) && self::isSensitiveKey($key)) {
                $context[$key] = '[redacted]';
            }
        }
        return $context;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = \strtolower($key);
        if (\in_array($lower, self::SENSITIVE_KEYS, true)) {
            return true;
        }
        foreach (\explode('_', $lower) as $token) {
            if (\in_array($token, self::SENSITIVE_TOKENS, true)) {
                return true;
            }
        }
        return false;
    }
}
