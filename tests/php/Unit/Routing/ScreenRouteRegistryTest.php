<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Routing\ScreenRouteRegistry;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class ScreenRouteRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('do_action')->justReturn(null);
        Functions\when('sanitize_key')->alias(
            static fn (string $v): string => \strtolower(\preg_replace('/[^a-z0-9_\-]/', '', $v) ?? '')
        );
        Functions\when('sanitize_text_field')->returnArg();
        ScreenRouteRegistry::reset();
    }

    protected function tearDown(): void
    {
        ScreenRouteRegistry::reset();
        Monkey\tearDown();
    }

    public function test_register_and_retrieve_round_trip(): void
    {
        ScreenRouteRegistry::register(
            'latest-premium',
            'Latest premium posts',
            static fn (App $app): array => ['post_type' => 'post'],
        );

        $all = ScreenRouteRegistry::all();
        self::assertArrayHasKey('latest-premium', $all);
        self::assertSame('Latest premium posts', $all['latest-premium']['label']);
    }

    public function test_duplicate_id_is_silently_ignored(): void
    {
        ScreenRouteRegistry::register('id', 'First', static fn ($a) => []);
        ScreenRouteRegistry::register('id', 'Second', static fn ($a) => []);

        self::assertSame('First', ScreenRouteRegistry::all()['id']['label']);
    }

    public function test_empty_id_is_rejected(): void
    {
        ScreenRouteRegistry::register('', 'Nameless', static fn ($a) => []);
        self::assertSame([], ScreenRouteRegistry::all());
    }

    public function test_resolve_runs_provider_with_app(): void
    {
        ScreenRouteRegistry::register(
            'q',
            'Query',
            static fn (App $app): array => ['post_type' => 'article', 'app_slug' => $app->slug()],
        );

        $app = new App(WP_Post::make(['ID' => 5, 'post_name' => 'my-app', 'post_status' => 'publish']));
        $args = ScreenRouteRegistry::resolve('q', $app);

        self::assertIsArray($args);
        self::assertSame('article', $args['post_type']);
        self::assertSame('my-app', $args['app_slug']);
    }

    public function test_resolve_unknown_id_returns_null(): void
    {
        $app = new App(WP_Post::make(['ID' => 1, 'post_status' => 'publish']));
        self::assertNull(ScreenRouteRegistry::resolve('nope', $app));
    }

    public function test_resolve_swallows_provider_exceptions_returns_null(): void
    {
        ScreenRouteRegistry::register('boom', 'Boom', static function (): array {
            throw new \RuntimeException('provider blew up');
        });

        $app = new App(WP_Post::make(['ID' => 1, 'post_status' => 'publish']));
        self::assertNull(ScreenRouteRegistry::resolve('boom', $app));
    }
}
