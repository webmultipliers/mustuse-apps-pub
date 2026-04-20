<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Blocks\KitchenSinkPatterns;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Manifest\CapabilityRegistry;
use MustUse\Pub\Manifest\ManifestBuilder;
use MustUse\Pub\Manifest\ManifestValidator;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * End-to-end test of the kitchen-sink pattern through the manifest pipeline.
 *
 * Proves that a publisher who drops the pattern onto a Screen — or uses
 * the KitchenSinkScaffold to create a demo app — ends up with a manifest
 * that: (a) validates; (b) carries the schema-version gate; (c) retains
 * every native-action block because the scaffold advertises the matching
 * capabilities; (d) collects the full `requiredCapabilities` array the
 * shell uses to refuse unsupported builds.
 *
 * Complements `ManifestContractTest` (shape-focused) by exercising the
 * specific block tree the demo-app scaffold produces.
 */
final class KitchenSinkManifestTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];
    /** @var array<int, WP_Post> */
    private array $screenPosts = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $parsedBlocks = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args) => $value
        );
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('rest_url')->alias(
            static fn (string $path = '') => 'https://pub.test/wp-json/' . \ltrim($path, '/')
        );
        Functions\when('wp_json_encode')->alias(static fn ($v, $f = 0) => \json_encode($v, $f));
        Functions\when('get_posts')->alias(
            function (array $args) {
                return $args['post_type'] === 'mua_app_screen' ? \array_values($this->screenPosts) : [];
            }
        );
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $k) => $this->postMeta[$id][$k] ?? ''
        );
        Functions\when('update_post_meta')->alias(
            function (int $id, string $k, mixed $v) {
                $this->postMeta[$id][$k] = $v;
                return true;
            }
        );
        Functions\when('delete_post_meta')->alias(function (int $id, string $k) {
            unset($this->postMeta[$id][$k]);
            return true;
        });
        Functions\when('parse_blocks')->alias(
            fn (string $c) => $this->parsedBlocks[(int) $c] ?? []
        );
        Functions\when('get_post_time')->justReturn(0);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_kitchen_sink_pattern_yields_a_valid_manifest(): void
    {
        $this->seedKitchenSinkApp();

        $manifest = (new ManifestBuilder())->build($this->makeApp());

        $errors = (new ManifestValidator())->validate($manifest, $this->makeApp());
        self::assertSame([], $errors, 'Manifest should validate. Errors: ' . \implode(' | ', $errors));
    }

    public function test_schema_version_fields_are_emitted(): void
    {
        $this->seedKitchenSinkApp();

        $manifest = (new ManifestBuilder())->build($this->makeApp());

        self::assertArrayHasKey('version',           $manifest);
        self::assertArrayHasKey('min_shell_version', $manifest);
        self::assertSame(ManifestBuilder::SCHEMA_VERSION,           $manifest['version']);
        self::assertSame(ManifestBuilder::MIN_SHELL_SCHEMA_VERSION, $manifest['min_shell_version']);
    }

    public function test_every_block_classified_capability_appears_in_required_capabilities(): void
    {
        $this->seedKitchenSinkApp();

        $manifest = (new ManifestBuilder())->build($this->makeApp());

        $required = $manifest['requiredCapabilities'] ?? [];
        $missing  = \array_diff(CapabilityRegistry::BLOCK_CAPABILITIES, $required);

        self::assertSame(
            [],
            \array_values($missing),
            'Kitchen-sink manifest missing requiredCapabilities: ' . \implode(', ', $missing)
        );
    }

    public function test_capability_filtering_strips_blocks_when_shell_does_not_advertise(): void
    {
        $this->seedKitchenSinkApp(['camera', 'scanner']); // narrow advertisement

        $manifest = (new ManifestBuilder())->build($this->makeApp());

        $caps = [];
        self::walkCollectCapabilities($manifest['screens'][0]['block_tree'] ?? [], $caps);

        self::assertContains('camera',  $caps);
        self::assertContains('scanner', $caps);
        self::assertNotContains('geolocation', $caps, 'Unadvertised caps must be filtered out.');
        self::assertNotContains('microphone',  $caps);
    }

    public function test_native_action_blocks_survive_when_all_capabilities_are_advertised(): void
    {
        $this->seedKitchenSinkApp();

        $manifest = (new ManifestBuilder())->build($this->makeApp());

        $caps = [];
        self::walkCollectCapabilities($manifest['screens'][0]['block_tree'] ?? [], $caps);

        $missing = \array_diff(CapabilityRegistry::BLOCK_CAPABILITIES, $caps);
        self::assertSame(
            [],
            \array_values($missing),
            'Every block-classified capability should reach the shell when the shell advertises it all. Missing: '
                . \implode(', ', $missing)
        );
    }

    /**
     * Seed an app + a single home screen whose `post_content` is the
     * kitchen-sink pattern. The capability advertisement defaults to
     * every known cap (matches `KitchenSinkScaffold::seedAdvertisement`).
     *
     * @param list<string>|null $advertisedCaps Pass a narrow list to test filtering.
     */
    private function seedKitchenSinkApp(?array $advertisedCaps = null): void
    {
        $caps = $advertisedCaps ?? \array_keys(CapabilityRegistry::MOBILE_CAPABILITIES);

        $native = [];
        foreach ($caps as $cap) {
            $native[$cap] = true;
        }
        $this->postMeta[10] = [
            '_mua_app_type'           => 'mobile_ios',
            '_mua_shell_capabilities' => [
                'app_type'            => 'mobile_ios',
                'shell_version'       => 'kitchen-sink-test',
                'native_capabilities' => $native,
            ],
        ];

        $screenId = 100;
        $this->screenPosts[$screenId] = WP_Post::make([
            'ID'           => $screenId,
            'post_name'    => 'home',
            'post_title'   => 'Kitchen Sink',
            'post_content' => (string) $screenId,
            'post_status'  => 'publish',
            'post_parent'  => 0,
            'menu_order'   => 0,
        ]);
        $this->postMeta[$screenId] = [
            '_mua_app_id'      => 10,
            '_mua_is_home'     => true,
            '_mua_screen_role' => 'static',
        ];

        // WP's parse_blocks is stubbed; hand it the decoded shape
        // directly so the ManifestBuilder walks the tour's combined
        // block tree (every capability page concatenated). Simulates
        // "shell rendering the entire tour" for manifest validation.
        $markup = \implode("\n", [
            KitchenSinkPatterns::home(),
            KitchenSinkPatterns::capture(),
            KitchenSinkPatterns::location(),
            KitchenSinkPatterns::audio(),
            KitchenSinkPatterns::sensors(),
            KitchenSinkPatterns::dialogs(),
            KitchenSinkPatterns::browser(),
            KitchenSinkPatterns::auth(),
            KitchenSinkPatterns::device(),
            KitchenSinkPatterns::push(),
        ]);
        $this->parsedBlocks[$screenId] = self::parsePattern($markup);
    }

    private function makeApp(): App
    {
        return new App(WP_Post::make([
            'ID'          => 10,
            'post_name'   => 'kitchen-sink',
            'post_title'  => 'Kitchen Sink',
            'post_status' => 'publish',
        ]));
    }

    /**
     * Shallow block-comment parser — mirrors wp-core's parse_blocks for the
     * flat shape our pattern emits (self-closing + the one auth-gate wrap).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function parsePattern(string $markup): array
    {
        $flat = [];
        \preg_match_all(
            '/<!--\s*wp:([a-z0-9-]+\/[a-z0-9-]+)(\s+(\{.*?\}))?\s*(\/)?-->/is',
            $markup,
            $m
        );
        foreach ($m[1] as $i => $name) {
            $attrJson = \trim((string) $m[3][$i]);
            $attrs    = $attrJson !== '' ? \json_decode($attrJson, true) : [];
            $flat[]   = [
                'blockName'   => (string) $name,
                'attrs'       => \is_array($attrs) ? $attrs : [],
                'innerBlocks' => [],
            ];
        }
        return $flat;
    }

    /**
     * @param array<mixed>  $blocks
     * @param list<string>  $caps
     */
    private static function walkCollectCapabilities(array $blocks, array &$caps): void
    {
        foreach ($blocks as $b) {
            if (! \is_array($b)) {
                continue;
            }
            $attributes = $b['attributes'] ?? [];
            $cap        = \is_array($attributes) ? ($attributes['capability'] ?? null) : null;
            if (\is_string($cap) && $cap !== '') {
                $caps[] = $cap;
            }
            $children = $b['children'] ?? ($b['innerBlocks'] ?? []);
            if (\is_array($children) && $children !== []) {
                self::walkCollectCapabilities($children, $caps);
            }
        }
    }
}
