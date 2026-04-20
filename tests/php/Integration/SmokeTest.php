<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Integration;

/**
 * Harness smoke check — confirms the WP test library booted, the plugin
 * loaded, and core objects are available. If this fails, no other
 * integration test can be trusted.
 */
final class SmokeTest extends MuaIntegrationTestCase
{
    public function test_wordpress_is_loaded(): void
    {
        self::assertSame(1, (int) \function_exists('wp_insert_post'));
        self::assertSame(1, (int) \defined('ABSPATH'));
    }

    public function test_plugin_bootstrapped(): void
    {
        self::assertSame(1, (int) \class_exists(\MustUse\Pub\Plugin::class));
        self::assertSame(1, (int) \defined('MUA_PUB_VERSION'));
    }

    public function test_custom_post_types_are_registered(): void
    {
        self::assertTrue(post_type_exists(\MustUse\Pub\Data\Models\App::POST_TYPE));
        self::assertTrue(post_type_exists(\MustUse\Pub\Data\Models\Screen::POST_TYPE));
    }

    public function test_rest_routes_are_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $muaRoutes = \array_filter(
            \array_keys($routes),
            static fn (string $r) => \str_starts_with($r, '/mustuse-apps-pub/')
        );
        self::assertNotEmpty($muaRoutes, 'Expected at least one /mustuse-apps-pub/ REST route.');
    }
}
