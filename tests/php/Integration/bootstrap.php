<?php

declare(strict_types=1);

/**
 * Integration test bootstrap — loads the real WordPress test harness.
 *
 * Run locally via @wordpress/env:
 *   npm run wp-env:start
 *   composer test:integration
 *
 * The WP_TESTS_DIR environment variable points at WordPress core's test
 * suite. Inside the wp-env `tests-cli` container it's already set; on the
 * host we fall back to vendor/wp-phpunit/wp-phpunit, which ships the test
 * library but not core itself — so host-side runs only work against a
 * wp-env or equivalent MySQL + WP install.
 */

// Prefer the composer-managed wp-phpunit package (PHPUnit 10 compatible)
// over whatever WP_TESTS_DIR points at — wp-env ships a pre-bundled
// /wordpress-phpunit whose abstract-testcase still calls removed APIs
// like PHPUnit\Util\Test::parseTestMethodAnnotations.
$testsDir = __DIR__ . '/../../../vendor/wp-phpunit/wp-phpunit';
if (! is_dir($testsDir)) {
    $testsDir = getenv('WP_TESTS_DIR') ?: $testsDir;
}

if (! file_exists($testsDir . '/includes/functions.php')) {
    fwrite(STDERR, sprintf(
        "Could not find WordPress test suite at %s.\n" .
        "Set WP_TESTS_DIR or install via `composer require --dev wp-phpunit/wp-phpunit`.\n",
        $testsDir
    ));
    exit(1);
}

require_once __DIR__ . '/../../../vendor/autoload.php';

require_once $testsDir . '/includes/functions.php';

/**
 * Activate the plugin before WP loads so its hooks register against the
 * same instance the tests interact with.
 */
tests_add_filter('muplugins_loaded', static function (): void {
    require_once __DIR__ . '/../../../mustuse-apps-pub.php';
});

require $testsDir . '/includes/bootstrap.php';

// Plugin activation hook doesn't fire in the WP test environment. Run the
// schema init once after WP bootstraps so tests see the real plugin schema.
\MustUse\Pub\Data\SchemaManager::activate();
