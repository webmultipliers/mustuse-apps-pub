<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap — declares plugin-defined constants so static analysis
 * doesn't flag them as unknown. The runtime defines these in
 * mustuse-apps-pub.php; we mirror them here as no-op fallbacks.
 *
 * Loaded via parameters.bootstrapFiles in phpstan.neon.dist.
 */

defined('MUA_PUB_VERSION') || define('MUA_PUB_VERSION', '0.0.0');
defined('MUA_PUB_FILE')    || define('MUA_PUB_FILE',    __FILE__);
defined('MUA_PUB_DIR')     || define('MUA_PUB_DIR',     __DIR__ . '/');
defined('MUA_PUB_URL')     || define('MUA_PUB_URL',     'https://example.test/');

// yoast/phpunit-polyfills declares Polyfill_TestCase at runtime via
// class_alias based on the detected PHPUnit version. Static analysis
// can't resolve that, so WP_UnitTestCase_Base (which extends
// PHPUnit_Adapter_TestCase extends Polyfill_TestCase) loses its chain
// up to PHPUnit\Framework\TestCase — which hides every inherited
// assertion method from integration tests. A stub here re-establishes
// the chain so PHPStan sees `assertSame`, `factory()`, etc.
if (! class_exists('Polyfill_TestCase', false)) {
    abstract class Polyfill_TestCase extends \PHPUnit\Framework\TestCase {}
}
