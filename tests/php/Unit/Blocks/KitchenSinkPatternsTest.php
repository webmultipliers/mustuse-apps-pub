<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Blocks\KitchenSinkPatterns;
use MustUse\Pub\Manifest\BlockValidator;
use MustUse\Pub\Manifest\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Pins the per-screen kitchen-sink patterns to the capability vocabulary
 * and validates each screen's block tree is well-formed. Guards against
 * a capability silently disappearing from the tour when new caps are
 * added to the registry.
 */
final class KitchenSinkPatternsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias(static fn ($v, $f = 0) => \json_encode($v, $f));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_every_screen_parses_and_validates(): void
    {
        foreach (self::screenMethods() as $method) {
            $markup = KitchenSinkPatterns::$method();
            $blocks = self::parseBlocks($markup);
            self::assertNotEmpty($blocks, "Pattern {$method} should parse to ≥1 block.");

            $errors = (new BlockValidator())->validate(self::serializeForValidator($blocks));
            self::assertSame([], $errors, "Pattern {$method} failed validator: " . \implode(' | ', $errors));
        }
    }

    public function test_capture_page_covers_every_media_capability(): void
    {
        $caps = self::collectCapabilities(KitchenSinkPatterns::capture());
        self::assertContains('camera',        $caps);
        self::assertContains('photo_library', $caps);
        self::assertContains('video_record',  $caps);
        self::assertContains('scanner',       $caps);
    }

    public function test_dialogs_page_covers_alert_toast_share(): void
    {
        $caps = self::collectCapabilities(KitchenSinkPatterns::dialogs());
        self::assertContains('dialog_alert', $caps);
        self::assertContains('dialog_toast', $caps);
        self::assertContains('share',        $caps);
    }

    public function test_browser_page_covers_all_three_variants(): void
    {
        $caps = self::collectCapabilities(KitchenSinkPatterns::browser());
        self::assertContains('browser_inapp',  $caps);
        self::assertContains('browser_system', $caps);
        self::assertContains('browser_auth',   $caps);
    }

    public function test_every_block_classified_capability_appears_somewhere(): void
    {
        $all = [];
        foreach (self::screenMethods() as $method) {
            $all = \array_merge($all, self::collectCapabilities(KitchenSinkPatterns::$method()));
        }
        $missing = \array_diff(CapabilityRegistry::BLOCK_CAPABILITIES, $all);
        self::assertSame(
            [],
            \array_values($missing),
            'Kitchen-sink tour is missing demo pages for: ' . \implode(', ', $missing)
        );
    }

    public function test_home_links_to_every_capability_page_via_screen_link_cards(): void
    {
        // Nav cards must use the `screen-link` block (renders as
        // `<a wire:navigate>`), NOT `browser_inapp` native-actions —
        // that capability opens the device browser and can't resolve
        // app-local paths.
        $home    = self::parseBlocks(KitchenSinkPatterns::home());
        $navUrls = [];
        foreach ($home as $block) {
            if (($block['blockName'] ?? '') !== 'mustuse-apps-pub/screen-link') {
                continue;
            }
            $attrs = $block['attrs'] ?? [];
            if (! empty($attrs['path'])) {
                $navUrls[] = (string) $attrs['path'];
            }
        }
        foreach ([ '/capture', '/location', '/audio', '/sensors', '/dialogs', '/browser', '/auth', '/device', '/push' ] as $path) {
            self::assertContains($path, $navUrls, "Home is missing a nav card for {$path}");
        }
    }

    /** @return list<string> */
    private static function screenMethods(): array
    {
        return [
            'home', 'capture', 'location', 'audio', 'sensors',
            'dialogs', 'browser', 'auth', 'device', 'push', 'notFound',
        ];
    }

    /** @return list<string> */
    private static function collectCapabilities(string $markup): array
    {
        $caps = [];
        foreach (self::parseBlocks($markup) as $b) {
            if (($b['blockName'] ?? '') !== 'mustuse-apps-pub/native-action') {
                continue;
            }
            $attrs = $b['attrs'] ?? [];
            if (! empty($attrs['capability'])) {
                $caps[] = (string) $attrs['capability'];
            }
        }
        return \array_values(\array_unique($caps));
    }

    /**
     * Shallow block-comment parser — mirrors wp-core's `parse_blocks`
     * for the self-closing + single-nested shape the pattern emits.
     *
     * @return list<array{blockName:string, attrs:array<string,mixed>, innerBlocks:list<array>}>
     */
    private static function parseBlocks(string $markup): array
    {
        $blocks = [];
        if (! \preg_match_all(
            '/<!--\s*wp:([a-z0-9-]+\/[a-z0-9-]+)(\s+(\{.*?\}))?\s*(\/)?-->/is',
            $markup,
            $m
        )) {
            return [];
        }
        foreach ($m[1] as $i => $name) {
            $attrJson = \trim((string) $m[3][$i]);
            $attrs    = $attrJson !== '' ? \json_decode($attrJson, true) : [];
            $blocks[] = [
                'blockName'   => (string) $name,
                'attrs'       => \is_array($attrs) ? $attrs : [],
                'innerBlocks' => [],
            ];
        }
        return $blocks;
    }

    /**
     * @param list<array{blockName:string, attrs:array<string,mixed>, innerBlocks:list<array>}> $blocks
     * @return list<array{type:string, attributes:array<string,mixed>, children:list<array>}>
     */
    private static function serializeForValidator(array $blocks): array
    {
        return \array_map(static fn (array $b): array => [
            'type'       => 'mustuse-apps-pub/' . \substr($b['blockName'], \strpos($b['blockName'], '/') + 1),
            'attributes' => $b['attrs'],
            'children'   => [],
        ], $blocks);
    }
}
