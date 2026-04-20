<?php
/**
 * Test config for the wp-phpunit (composer) harness, read via the
 * WP_PHPUNIT__TESTS_CONFIG env var. Values match wp-env's tests-cli
 * container defaults (tests-mysql / tests-wordpress / root:password).
 */

define('DB_NAME', getenv('WORDPRESS_DB_NAME') ?: 'tests-wordpress');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'password');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'tests-mysql');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('ABSPATH', '/var/www/html/');
define('WP_DEBUG', true);
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'MustUse Apps — Integration Tests');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
