<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Manifest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Extensions\ExtensionRegistry;
use MustUse\Pub\Manifest\ManifestBuilder;
use MustUse\Pub\Manifest\ManifestValidator;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * End-to-end ManifestBuilder tests — feeds a stubbed WordPress environment
 * into the real builder and asserts the compiled manifest matches the
 * pub-shell manifest contract. Each manifest is also piped through
 * ManifestValidator to catch contract drift.
 *
 * These are unit-level (no DB, no WP) but exercise the full compile path.
 * True integration tests against real WP belong in the integration suite.
 */
final class ManifestBuilderTest extends TestCase
{
    /** @var array<int, array<string, mixed>> App post meta, keyed by post ID. */
    private array $postMeta = [];
    /** @var array<string, mixed> wp_options store. */
    private array $options = [];
    /** @var WP_Post[] Screen posts returned by Screen::findByApp() for the current app. */
    private array $screens = [];
    /** @var array<int, array<string, mixed>> Parsed block trees keyed by post ID. */
    private array $parsedBlocks = [];

    private function getOption(string $key, mixed $default = false): mixed
    {
        return $this->options[$key] ?? $default;
    }

    protected function setUp(): void
    {
        Monkey\setUp();
        ExtensionRegistry::reset();

        $this->postMeta = [];
        $this->options = [];
        $this->screens = [];
        $this->parsedBlocks = [];

        Functions\when('__')->returnArg(1);

        // apply_filters → identity (first non-named arg passed through unchanged)
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args) => $value
        );

        Functions\when('get_option')->alias(
            fn (string $key, mixed $default = false) => $this->getOption($key, $default)
        );

        Functions\when('update_option')->alias(
            function (string $key, mixed $value) {
                $this->options[$key] = $value;
                return true;
            }
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

        Functions\when('rest_url')->alias(
            static fn (string $path = '') => 'https://pub.test/wp-json/' . \ltrim($path, '/')
        );

        Functions\when('get_posts')->alias(
            function (array $args) {
                $type = $args['post_type'] ?? '';
                return match ($type) {
                    'mua_app_screen' => $this->screens,
                    default          => [],
                };
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

        // Screen::blockTree() calls parse_blocks( post_content ).
        // Our stub dispatches by post ID via the `post_content` marker we set.
        Functions\when('parse_blocks')->alias(
            function (string $content) {
                $id = (int) $content;
                return $this->parsedBlocks[$id] ?? [];
            }
        );

        Functions\when('get_post_time')->justReturn(0);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        ExtensionRegistry::reset();
    }

    /**
     * Register a Screen post that will be returned by Screen::findByApp for
     * the current app. `$parsedBlocks` should use WP's raw parse_blocks
     * shape (blockName/attrs/innerBlocks).
     */
    private function addScreen(int $id, string $slug, string $title, array $parsedBlocks = []): void
    {
        $this->screens[] = WP_Post::make([
            'ID' => $id,
            'post_name' => $slug,
            'post_title' => $title,
            'post_content' => (string) $id,
            'post_status' => 'publish',
        ]);
        $this->parsedBlocks[$id] = $parsedBlocks;
    }

    private function makeApp(int $id = 10, string $slug = 'my-app', string $title = 'My App', string $appType = 'mobile_ios'): App
    {
        $this->postMeta[$id] = ['_mua_app_type' => $appType];
        return new App(WP_Post::make([
            'ID' => $id,
            'post_name' => $slug,
            'post_title' => $title,
            'post_status' => 'publish',
        ]));
    }

    public function test_empty_app_produces_all_required_top_level_fields(): void
    {
        $manifest = (new ManifestBuilder())->build($this->makeApp());

        self::assertSame(ManifestBuilder::SCHEMA_VERSION, $manifest['version']);
        self::assertSame('my-app', $manifest['app']['slug']);
        self::assertSame('mobile_ios', $manifest['app']['app_type']);
        // app_types plural is the canonical list; singular stays for back-compat.
        // This fixture seeds only the legacy `_mua_app_type` meta, so the
        // plural list comes from the migration path (singleton list).
        self::assertSame(['mobile_ios'], $manifest['app']['app_types']);
        self::assertSame('My App', $manifest['app']['name']);
        self::assertSame([], $manifest['screens']);
        self::assertSame([], $manifest['requiredCapabilities']);
        self::assertSame([], $manifest['extensions']);
        self::assertNull($manifest['deeplink']);
        self::assertNull($manifest['subscriber_state']);
        self::assertArrayHasKey('cache_policy', $manifest);
        self::assertArrayHasKey('endpoints', $manifest);
        self::assertStringContainsString('/apps/my-app/content', $manifest['endpoints']['content']);
    }

    public function test_published_screen_is_serialised_in_manifest(): void
    {
        $app = $this->makeApp();
        $this->addScreen(100, 'home', 'Home', [[
            'blockName' => 'mustuse-apps-pub/text',
            'attrs' => ['content' => 'Hello'],
            'innerBlocks' => [],
        ]]);

        $manifest = (new ManifestBuilder())->build($app);

        self::assertCount(1, $manifest['screens']);
        self::assertSame('home', $manifest['screens'][0]['id']);
        self::assertSame('screen', $manifest['screens'][0]['type']);
        self::assertSame('Home', $manifest['screens'][0]['title']);
        self::assertCount(1, $manifest['screens'][0]['block_tree']);
    }

    public function test_branding_overrides_publisher_defaults(): void
    {
        $app = $this->makeApp();
        $this->options['mua_publisher_branding'] = ['primary_color' => '#000', 'secondary' => '#aaa'];
        $this->postMeta[10]['_mua_branding'] = ['primary_color' => '#ff0000'];

        $manifest = (new ManifestBuilder())->build($app);

        self::assertSame('#ff0000', $manifest['branding']['primary_color']);
        self::assertSame('#aaa', $manifest['branding']['secondary']);
    }

    public function test_assets_read_from_branding_meta(): void
    {
        $app = $this->makeApp();
        $this->postMeta[10]['_mua_branding'] = [
            'icon_url'   => '/uploads/icon.png',
            'logo_url'   => '/uploads/logo.svg',
            'splash_url' => '/uploads/splash.jpg',
        ];

        $manifest = (new ManifestBuilder())->build($app);

        self::assertSame('/uploads/icon.png', $manifest['assets']['icon']);
        self::assertSame('/uploads/logo.svg', $manifest['assets']['logo']);
        self::assertSame('/uploads/splash.jpg', $manifest['assets']['splash']);
    }

    public function test_deeplink_emitted_only_when_meta_present(): void
    {
        $app = $this->makeApp();
        $this->postMeta[10]['_mua_deeplink_scheme'] = 'myapp';
        $this->postMeta[10]['_mua_deeplink_host']   = 'example.com';

        $manifest = (new ManifestBuilder())->build($app);

        self::assertSame(['scheme' => 'myapp', 'host' => 'example.com'], $manifest['deeplink']);
    }

    public function test_required_capabilities_collected_from_blocks(): void
    {
        $app = $this->makeApp();
        // Shell must advertise the capability or the block is stripped before
        // collectRequiredCapabilities sees it.
        $this->postMeta[10]['_mua_shell_capabilities'] = [
            'native_capabilities' => ['camera' => true],
        ];
        $this->addScreen(200, 'scanner', 'Scanner', [[
            'blockName' => 'mustuse-apps-pub/native-action',
            'attrs' => ['capability' => 'camera', 'callbackType' => 'state', 'callbackSlot' => 's'],
            'innerBlocks' => [],
        ], [
            'blockName' => 'mustuse-apps-pub/auth-gate',
            'attrs' => [],
            'innerBlocks' => [],
        ]]);

        $manifest = (new ManifestBuilder())->build($app);

        self::assertContains('camera', $manifest['requiredCapabilities']);
        self::assertContains('biometrics', $manifest['requiredCapabilities']);
    }

    public function test_cache_policy_defaults_are_applied(): void
    {
        $manifest = (new ManifestBuilder())->build($this->makeApp());

        self::assertSame(300, $manifest['cache_policy']['manifest']['ttl_seconds']);
        self::assertSame('stale-while-revalidate', $manifest['cache_policy']['content']['strategy']);
        self::assertSame(86400, $manifest['cache_policy']['assets']['ttl_seconds']);
    }

    public function test_cache_invalidations_default_to_zero_strings(): void
    {
        $manifest = (new ManifestBuilder())->build($this->makeApp());

        self::assertSame('0', $manifest['cache_invalidations']['manifest']);
        self::assertSame('0', $manifest['cache_invalidations']['content']);
        self::assertSame('0', $manifest['cache_invalidations']['assets']);
    }

    public function test_endpoints_are_slug_scoped_and_cover_the_full_surface(): void
    {
        $app = $this->makeApp(10, 'cool-app');
        $manifest = (new ManifestBuilder())->build($app);

        foreach (['content','capabilities','resolve_deeplink','auth_complete','auth_status','auth_logout','subscriber_state'] as $key) {
            self::assertArrayHasKey($key, $manifest['endpoints']);
            self::assertStringContainsString('/apps/cool-app/', $manifest['endpoints'][$key]);
        }
    }

    public function test_unadvertised_block_capabilities_are_stripped(): void
    {
        $app = $this->makeApp();
        // Advertise only camera — scanner should be filtered out.
        $this->postMeta[10]['_mua_shell_capabilities'] = [
            'native_capabilities' => ['camera' => true],
        ];
        $this->addScreen(400, 'home', 'Home', [
            ['blockName' => 'mustuse-apps-pub/native-action', 'attrs' => ['capability' => 'camera', 'callbackType' => 'state', 'callbackSlot' => 's'], 'innerBlocks' => []],
            ['blockName' => 'mustuse-apps-pub/native-action', 'attrs' => ['capability' => 'scanner', 'callbackType' => 'state', 'callbackSlot' => 's'], 'innerBlocks' => []],
        ]);

        $manifest = (new ManifestBuilder())->build($app);

        $capabilities = \array_map(
            static fn (array $b) => $b['attributes']['capability'] ?? null,
            $manifest['screens'][0]['block_tree']
        );
        self::assertContains('camera', $capabilities);
        self::assertNotContains('scanner', $capabilities);
    }

    public function test_built_manifest_passes_validator(): void
    {
        $app = $this->makeApp();
        $this->addScreen(500, 'home', 'Home', []);

        $manifest = (new ManifestBuilder())->build($app);
        $errors = (new ManifestValidator())->validate($manifest, $app);

        self::assertSame([], $errors, 'Compiled manifest should satisfy the validator contract.');
    }
}
