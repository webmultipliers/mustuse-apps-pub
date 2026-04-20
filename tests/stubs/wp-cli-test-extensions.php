<?php

/**
 * PHPStan stub: extra static properties and methods added to WP_CLI by
 * the test shim (tests/stubs/wp-classes.php). Listed here WITHOUT a
 * class_exists guard so PHPStan always merges them with the wp-cli-stubs
 * definition.
 *
 * This file is referenced from phpstan.neon.dist → stubFiles.
 */
class WP_CLI
{
    /** @var string[] */
    public static array $log;
    /** @var string[] */
    public static array $errors;
    /** @var string[] */
    public static array $warnings;
    /** @var string[] */
    public static array $successes;
    /** @var array<string, mixed> */
    public static array $commands;

    public static function reset(): void {}

    /**
     * @param string $name
     * @param mixed $callable
     */
    public static function add_command(string $name, mixed $callable): void {}

    /**
     * @param string $message
     */
    public static function log(string $message): void {}

    /**
     * @param string $message
     */
    public static function error(string $message): void {}

    /**
     * @param string $message
     */
    public static function warning(string $message): void {}

    /**
     * @param string $message
     */
    public static function success(string $message): void {}
}
