<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Manifest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Manifest\ManifestBuilder;
use MustUse\Pub\Manifest\ManifestValidator;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * End-to-end contract test pinning the manifest shape the shell consumes.
 *
 * Builds a realistic multi-screen manifest, runs it through the validator,
 * and re-implements the same key reads NativeEdge does on the device side
 * (path matching, home flag, navigation surfaces). If projector and shell
 * ever drift in shape, this test fails with the exact field that broke.
 */
final class ManifestContractTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];
    /** @var array<int, WP_Post> */
    private array $screenPosts = [];
    /** @var array<int, array<string, mixed>> */
    private array $parsedBlocks = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->postMeta     = [];
        $this->screenPosts  = [];
        $this->parsedBlocks = [];

        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args) => $value
        );
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('rest_url')->alias(
            static fn (string $path = '') => 'https://pub.test/wp-json/' . \ltrim($path, '/')
        );
        Functions\when('wp_parse_args')->alias(
            static function (array|string $args, array $defaults = []) {
                if (\is_string($args)) {
                    \parse_str($args, $parsed);
                    $args = $parsed;
                }
                return \array_merge($defaults, $args);
            }
        );
        Functions\when('get_posts')->alias(
            function (array $args) {
                $type = $args['post_type'] ?? '';
                if ($type !== 'mua_app_screen') {
                    return [];
                }
                $rows = $this->screenPosts;
                \usort($rows, static function (WP_Post $a, WP_Post $b): int {
                    return $a->menu_order <=> $b->menu_order ?: $a->ID <=> $b->ID;
                });
                return $rows;
            }
        );
        Functions\when('get_post_meta')->alias(
            function (int $postId, string $key) {
                return $this->postMeta[$postId][$key] ?? '';
            }
        );
        Functions\when('update_post_meta')->alias(
            function (int $postId, string $key, mixed $value) {
                $this->postMeta[$postId][$key] = $value;
                return true;
            }
        );
        Functions\when('delete_post_meta')->alias(
            function (int $postId, string $key) {
                unset($this->postMeta[$postId][$key]);
                return true;
            }
        );
        Functions\when('parse_blocks')->alias(
            function (string $content) {
                return $this->parsedBlocks[(int) $content] ?? [];
            }
        );
        Functions\when('get_post_time')->justReturn(0);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    private function makeApp(): App
    {
        $this->postMeta[10] = ['_mua_app_type' => 'mobile_ios'];
        return new App(WP_Post::make([
            'ID'          => 10,
            'post_name'   => 'news',
            'post_title'  => 'News',
            'post_status' => 'publish',
        ]));
    }

    private function addScreen(int $id, string $slug, string $title, array $meta = [], array $blocks = []): void
    {
        $post = WP_Post::make([
            'ID'           => $id,
            'post_name'    => $slug,
            'post_title'   => $title,
            'post_content' => (string) $id,
            'post_status'  => 'publish',
            'post_parent'  => (int) ($meta['_mua_parent'] ?? 0),
            'menu_order'   => (int) ($meta['_mua_menu_order'] ?? 0),
        ]);
        $this->screenPosts[$id]  = $post;
        $this->postMeta[$id]     = \array_merge(['_mua_app_id' => 10], $meta);
        $this->parsedBlocks[$id] = $blocks;
    }

    private function buildManifest(): array
    {
        return (new ManifestBuilder())->build($this->makeApp());
    }

    public function test_built_manifest_passes_validator(): void
    {
        $this->addScreen(100, 'home', 'Home', ['_mua_is_home' => true]);

        $manifest = $this->buildManifest();

        $errors = (new ManifestValidator())->validate($manifest, $this->makeApp());
        self::assertSame([], $errors);
    }

    public function test_explicit_home_screen_routes_at_root(): void
    {
        $this->addScreen(100, 'first',  'First');
        $this->addScreen(101, 'second', 'Second', ['_mua_is_home' => true]);

        $manifest = $this->buildManifest();

        $home = $this->resolveScreenByPath($manifest, '/');
        self::assertNotNull($home);
        self::assertSame('second', $home['id']);
        self::assertTrue($home['is_home'] ?? false);
    }

    public function test_implicit_home_falls_back_to_first_show_in_nav_screen(): void
    {
        $this->addScreen(100, 'inbox',    'Inbox');
        $this->addScreen(101, 'feed',     'Feed', [
            '_mua_show_in_nav' => true,
            '_mua_nav_order'   => 0,
        ]);
        $this->addScreen(102, 'settings', 'Settings', [
            '_mua_show_in_nav' => true,
            '_mua_nav_order'   => 1,
        ]);

        $home = $this->resolveScreenByPath($this->buildManifest(), '/');
        self::assertNotNull($home);
        self::assertSame('feed', $home['id']);
    }

    public function test_non_home_static_screens_resolve_at_slug_path(): void
    {
        $this->addScreen(100, 'home',  'Home', ['_mua_is_home' => true]);
        $this->addScreen(101, 'about', 'About');

        $manifest = $this->buildManifest();

        $about = $this->resolveScreenByPath($manifest, '/about');
        self::assertNotNull($about);
        self::assertSame('about', $about['id']);
        self::assertSame('/about', $about['path']);
    }

    public function test_detail_screen_path_uses_deeplink_template(): void
    {
        $this->addScreen(100, 'home', 'Home', ['_mua_is_home' => true]);
        $this->addScreen(101, 'article-detail', 'Article', [
            '_mua_screen_role'   => 'detail',
            '_mua_deeplink_path' => '/article/{slug}',
        ]);

        $manifest = $this->buildManifest();

        $detail = $this->findScreenById($manifest, 'article-detail');
        self::assertNotNull($detail);
        self::assertSame('/article/{slug}', $detail['path']);
    }

    public function test_bottom_nav_contract_shape(): void
    {
        $this->addScreen(100, 'home', 'Home', [
            '_mua_is_home'     => true,
            '_mua_show_in_nav' => true,
            '_mua_nav_order'   => 0,
        ]);
        $this->addScreen(101, 'profile', 'Profile', [
            '_mua_show_in_nav' => true,
            '_mua_nav_order'   => 1,
        ]);

        $manifest = $this->buildManifest();

        $bottomNav = $manifest['navigation']['bottom_nav'] ?? null;
        self::assertIsArray($bottomNav);
        self::assertCount(2, $bottomNav);
        foreach ($bottomNav as $tab) {
            self::assertArrayHasKey('screen_id', $tab);
            self::assertArrayHasKey('path',      $tab);
            self::assertArrayHasKey('label',     $tab);
            self::assertArrayHasKey('icon',      $tab);
        }
        self::assertSame('home',    $bottomNav[0]['screen_id']);
        self::assertSame('/',       $bottomNav[0]['path']);
        self::assertSame('profile', $bottomNav[1]['screen_id']);
        self::assertSame('/profile', $bottomNav[1]['path']);
    }

    public function test_side_nav_curated_list_in_order(): void
    {
        $this->addScreen(100, 'home', 'Home', ['_mua_is_home' => true]);
        $this->addScreen(101, 'inbox', 'Inbox', [
            '_mua_show_in_side_nav' => true,
            '_mua_side_nav_order'   => 1,
        ]);
        $this->addScreen(102, 'archive', 'Archive', [
            '_mua_show_in_side_nav' => true,
            '_mua_side_nav_order'   => 0,
        ]);

        $manifest = $this->buildManifest();

        $sideNav = $manifest['navigation']['side_nav'] ?? null;
        self::assertIsArray($sideNav);
        self::assertCount(2, $sideNav);
        self::assertSame('archive', $sideNav[0]['screen_id']);
        self::assertSame('inbox',   $sideNav[1]['screen_id']);
    }

    public function test_drawer_carries_full_screen_tree_with_children(): void
    {
        $this->addScreen(100, 'home',     'Home', ['_mua_is_home' => true, '_mua_menu_order' => 0]);
        $this->addScreen(101, 'settings', 'Settings', ['_mua_menu_order' => 1]);
        $this->addScreen(102, 'profile',  'Profile', ['_mua_parent' => 101, '_mua_menu_order' => 0]);
        $this->addScreen(103, 'security', 'Security', ['_mua_parent' => 101, '_mua_menu_order' => 1]);

        $manifest = $this->buildManifest();

        $drawer = $manifest['navigation']['drawer'] ?? null;
        self::assertIsArray($drawer);
        $byId = \array_column($drawer, null, 'screen_id');
        self::assertArrayHasKey('home',     $byId);
        self::assertArrayHasKey('settings', $byId);
        self::assertCount(2, $byId['settings']['children']);
        self::assertSame('profile',  $byId['settings']['children'][0]['screen_id']);
        self::assertSame('security', $byId['settings']['children'][1]['screen_id']);
        self::assertSame('/profile', $byId['settings']['children'][0]['path']);
    }

    public function test_unknown_path_returns_null_so_shell_falls_to_deeplink_resolver(): void
    {
        $this->addScreen(100, 'home', 'Home', ['_mua_is_home' => true]);
        $this->addScreen(101, 'about', 'About');

        $manifest = $this->buildManifest();

        self::assertNull($this->resolveScreenByPath($manifest, '/article/never-published'));
    }

    public function test_path_match_strips_query_string(): void
    {
        $this->addScreen(100, 'home',   'Home', ['_mua_is_home' => true]);
        $this->addScreen(101, 'search', 'Search');

        $manifest = $this->buildManifest();

        $screen = $this->resolveScreenByPath($manifest, '/search?q=hello');
        self::assertNotNull($screen);
        self::assertSame('search', $screen['id']);
    }

    /**
     * Mirrors NativeEdge::resolveScreen — match by path, then fall through
     * to is_home for `/`. If that drifts, this test fails to surface the
     * shell would behave differently.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>|null
     */
    private function resolveScreenByPath(array $manifest, string $path): ?array
    {
        $screens  = $manifest['screens'] ?? [];
        $pathOnly = \strtok($path, '?');
        $target   = \rtrim((string) $pathOnly, '/') ?: '/';

        foreach ($screens as $screen) {
            if (! \is_array($screen)) {
                continue;
            }
            $screenPath = \rtrim((string) ($screen['path'] ?? ''), '/') ?: '/';
            if ($screenPath === $target) {
                return $screen;
            }
        }

        if ($target === '/') {
            foreach ($screens as $screen) {
                if (\is_array($screen) && ! empty($screen['is_home'])) {
                    return $screen;
                }
            }
        }
        return null;
    }

    /**
     * Mirrors NativeEdge::findScreenById.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>|null
     */
    private function findScreenById(array $manifest, string $screenId): ?array
    {
        foreach (($manifest['screens'] ?? []) as $screen) {
            if (\is_array($screen) && ($screen['id'] ?? '') === $screenId) {
                return $screen;
            }
        }
        return null;
    }
}
