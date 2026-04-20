<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader and Brain\Monkey so that unit tests can
 * stub out WordPress globals (get_option, update_option, apply_filters, …)
 * without bootstrapping a real WordPress install.
 *
 * Integration tests that need a real WP environment should live under a
 * separate testsuite with its own bootstrap.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../stubs/wp-classes.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
