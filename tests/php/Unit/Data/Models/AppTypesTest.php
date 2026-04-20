<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Data\Models;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Multi-platform app-type semantics. Freezes three contracts:
 *  - `appTypes()` returns the stored array when present.
 *  - `appTypes()` migrates from the legacy singular `_mua_app_type` meta
 *    when only that key exists (no write back — the next save does it).
 *  - `appType()` still works and returns the first entry, so call sites
 *    that need a single primary platform (env vars, per-shell manifest
 *    field) don't have to know about the plural form.
 */
final class AppTypesTest extends TestCase
{
    /** @var array<int, array<string, mixed>> Emulated post_meta store. */
    private array $meta = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->meta = [];

        Functions\when('get_post_meta')->alias(
            /** @phpstan-ignore-next-line nullCoalesce.offset — property mutates after setUp */
            fn (int $postId, string $key, bool $single = false) => $this->meta[$postId][$key] ?? ''
        );
        Functions\when('update_post_meta')->alias(
            function (int $postId, string $key, mixed $value) {
                $this->meta[$postId][$key] = $value;
                return true;
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    private function makeApp(int $id = 7): App
    {
        return new App(WP_Post::make(['ID' => $id]));
    }

    public function test_default_when_no_meta_is_both_mobile_platforms(): void
    {
        $app = $this->makeApp();
        self::assertSame(['mobile_ios', 'mobile_android'], $app->appTypes());
    }

    public function test_appTypes_reads_stored_array(): void
    {
        $this->meta[7]['_mua_app_types'] = ['mobile_android'];
        $app = $this->makeApp();
        self::assertSame(['mobile_android'], $app->appTypes());
    }

    public function test_appTypes_filters_out_unknown_entries(): void
    {
        $this->meta[7]['_mua_app_types'] = ['mobile_ios', 'not_a_platform', ''];
        $app = $this->makeApp();
        self::assertSame(['mobile_ios'], $app->appTypes());
    }

    public function test_appTypes_dedupes(): void
    {
        $this->meta[7]['_mua_app_types'] = ['mobile_ios', 'mobile_ios', 'mobile_android'];
        $app = $this->makeApp();
        self::assertSame(['mobile_ios', 'mobile_android'], $app->appTypes());
    }

    public function test_appTypes_migrates_from_legacy_singular_meta(): void
    {
        // No `_mua_app_types` — only the old singular value.
        $this->meta[7]['_mua_app_type'] = 'mobile_android';
        $app = $this->makeApp();
        self::assertSame(['mobile_android'], $app->appTypes());
    }

    public function test_appType_returns_first_entry_as_primary(): void
    {
        $this->meta[7]['_mua_app_types'] = ['mobile_android', 'mobile_ios'];
        $app = $this->makeApp();
        self::assertSame('mobile_android', $app->appType());
    }

    public function test_appType_falls_back_to_ios_when_empty(): void
    {
        // Stored empty array after filtering.
        $this->meta[7]['_mua_app_types'] = ['not_valid'];
        $app = $this->makeApp();
        // No valid types in stored array, no legacy value → defaults kick in.
        self::assertSame(['mobile_ios', 'mobile_android'], $app->appTypes());
        self::assertSame('mobile_ios', $app->appType());
    }

    public function test_setAppTypes_writes_filtered_canonical_list(): void
    {
        $app = $this->makeApp();
        $app->setAppTypes(['mobile_ios', 'desktop_macos', 'mobile_ios']);

        // desktop_macos filtered (valid name but not v1), duplicates removed.
        self::assertSame(
            ['mobile_ios', 'desktop_macos'],
            $this->meta[7]['_mua_app_types'],
            'Invalid shapes are still filtered via isValidAppType at write time; ' .
            'isSupportedAppTypes is the caller-side guard.'
        );
    }

    public function test_setAppType_routes_through_setAppTypes(): void
    {
        $app = $this->makeApp();
        $app->setAppType('mobile_android');

        self::assertSame(['mobile_android'], $this->meta[7]['_mua_app_types']);
    }

    public function test_isSupportedAppTypes_requires_non_empty_mobile_list(): void
    {
        self::assertTrue(App::isSupportedAppTypes(['mobile_ios']));
        self::assertTrue(App::isSupportedAppTypes(['mobile_ios', 'mobile_android']));
        self::assertFalse(App::isSupportedAppTypes([]));
        self::assertFalse(App::isSupportedAppTypes(['mobile_ios', 'desktop_macos']));
        self::assertFalse(App::isSupportedAppTypes(['unknown']));
    }
}
