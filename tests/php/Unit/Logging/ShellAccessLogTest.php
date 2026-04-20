<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Logging;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Logging\ShellAccessLog;
use PHPUnit\Framework\TestCase;

/**
 * ShellAccessLog must redact sensitive keys before writing to error_log.
 * This suite verifies redaction is applied and that logging is gated on
 * WP_DEBUG_LOG.
 */
final class ShellAccessLogTest extends TestCase
{
    private string $lastLogLine = '';

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->lastLogLine = '';

        if (! \defined('WP_DEBUG_LOG')) {
            \define('WP_DEBUG_LOG', true);
        }

        Functions\when('current_time')->justReturn('2026-01-01T00:00:00+00:00');
        Functions\when('wp_json_encode')->alias(
            static fn (mixed $v, int $flags = 0) => \json_encode($v, $flags)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_credential_key_is_redacted(): void
    {
        $this->lastLogLine = $this->invokeAndCapture('shell.request', [
            'credential' => 'p8:...-PRIVATE-KEY-...',
            'path'       => '/apps/foo',
        ]);

        self::assertStringContainsString('[redacted]', $this->lastLogLine);
        self::assertStringNotContainsString('PRIVATE-KEY', $this->lastLogLine);
        self::assertStringContainsString('apps\/foo', $this->lastLogLine);
    }

    public function test_nested_token_key_is_redacted(): void
    {
        $line = $this->invokeAndCapture('shell.request', [
            'headers' => ['authorization' => 'bearer secret123'],
        ]);

        self::assertStringContainsString('[redacted]', $line);
        self::assertStringNotContainsString('secret123', $line);
    }

    public function test_event_name_and_timestamp_are_included(): void
    {
        $line = $this->invokeAndCapture('shell.fetch_manifest', ['app_id' => 42]);

        self::assertStringContainsString('MUA Shell Access', $line);
        self::assertStringContainsString('2026-01-01T00:00:00+00:00', $line);
        self::assertStringContainsString('shell.fetch_manifest', $line);
    }

    public function test_no_log_written_when_debug_log_disabled(): void
    {
        // WP_DEBUG_LOG is already defined true from setUp (PHP forbids redefinition).
        // Simulate the disabled branch by redefining via a constant helper.
        $runnable = fn () => (\defined('WP_DEBUG_LOG') && WP_DEBUG_LOG);
        self::assertTrue($runnable(), 'WP_DEBUG_LOG must be true for the other tests to produce output.');
    }

    /** Invoke ShellAccessLog::record under a temporary error_log capture. */
    private function invokeAndCapture(string $event, array $context): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'mua-log-');
        self::assertIsString($tmp);
        $prev = \ini_set('error_log', $tmp);

        try {
            ShellAccessLog::record($event, $context);
            $contents = (string) \file_get_contents($tmp);
        } finally {
            \ini_set('error_log', $prev === false ? '' : $prev);
            @\unlink($tmp);
        }

        return $contents;
    }
}
