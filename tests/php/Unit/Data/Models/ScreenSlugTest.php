<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Data\Models;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\Screen;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class ScreenSlugTest extends TestCase
{
    /** @var array<int, WP_Post> */
    private array $postsById = [];
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];
    /** @var array<int, string> */
    private array $appSlugs = [];
    /** @var array<int, string> */
    private array $renamed = [];
    /** @var array<int, array<string, mixed>> */
    private array $metaWrites = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_post')->alias(
            fn (int $id): ?WP_Post => $this->postsById[$id] ?? null
        );
        Functions\when('get_post_meta')->alias(
            function (int $id, string $key) {
                return $this->postMeta[$id][$key] ?? '';
            }
        );
        Functions\when('get_post_field')->alias(
            fn (string $field, int $id) => $field === 'post_name' ? ($this->appSlugs[$id] ?? '') : ''
        );
        // `intendedSlugInUse()` uses a meta_query; stub it by walking
        // our fixture meta tables. `$args['exclude']` is always honoured
        // so a screen never matches itself.
        Functions\when('get_posts')->alias(
            function (array $args) {
                $exclude = (array) ($args['exclude'] ?? []);
                $metaQ   = $args['meta_query'] ?? null;
                if (! \is_array($metaQ)) {
                    return [];
                }

                $filters = [];
                foreach ($metaQ as $k => $row) {
                    if ($k === 'relation' || ! \is_array($row)) {
                        continue;
                    }
                    if (isset($row['key'], $row['value'])) {
                        $filters[(string) $row['key']] = $row['value'];
                    }
                }

                $matches = [];
                foreach ($this->postMeta as $id => $meta) {
                    if (\in_array($id, $exclude, true)) {
                        continue;
                    }
                    $ok = true;
                    foreach ($filters as $key => $value) {
                        if (($meta[$key] ?? null) != $value) {
                            $ok = false;
                            break;
                        }
                    }
                    if ($ok && isset($this->postsById[$id])) {
                        $matches[] = $id;
                    }
                }
                return $matches;
            }
        );

        // `writePostName` goes straight to $wpdb to bypass
        // wp_unique_post_slug. Mirror it here with a closure shim so
        // tests can observe the post_name rewrite without caring about
        // the SQL layer.
        $postsById =& $this->postsById;
        $renamed   =& $this->renamed;
        $onUpdate  = static function (int $id, string $slug) use (&$postsById, &$renamed): void {
            if (isset($postsById[$id])) {
                $postsById[$id]->post_name = $slug;
                $renamed[$id]              = $slug;
            }
        };

        global $wpdb;
        $wpdb = new class ($onUpdate) {
            public string $posts = 'wp_posts';
            /** @var \Closure(int, string): void */
            private \Closure $onUpdate;
            public function __construct(\Closure $onUpdate)
            {
                $this->onUpdate = $onUpdate;
            }
            /** @param array<string, mixed> $data @param array<string, mixed> $where */
            public function update(string $_table, array $data, array $where): int
            {
                $id   = (int) ($where['ID'] ?? 0);
                $slug = (string) ($data['post_name'] ?? '');
                if ($id > 0 && $slug !== '') {
                    ($this->onUpdate)($id, $slug);
                    return 1;
                }
                return 0;
            }
        };
        Functions\when('clean_post_cache')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    /**
     * Seed an app + a screen. `$postName` is the pre-normaliser value
     * of `post_name` (what WP left after `wp_insert_post` ran). Meta
     * starts empty; individual tests override as needed.
     */
    private function addScreen(int $id, int $appId, string $postName, string $appSlug = 'my-app'): void
    {
        $this->postsById[$id] = WP_Post::make([
            'ID'          => $id,
            'post_name'   => $postName,
            'post_title'  => 'Screen ' . $id,
            'post_status' => 'publish',
        ]);
        $this->postsById[$id]->post_type = Screen::POST_TYPE;
        $this->postMeta[$id]  = ['_mua_app_id' => $appId];
        $this->appSlugs[$appId] = $appSlug;
        if (! isset($this->postMeta[$appId])) {
            $this->postMeta[$appId] = [];
        }
    }

    /** Stub `update_post_meta` to record to $this->metaWrites + mutate $postMeta. */
    private function captureMetaWrites(): void
    {
        Functions\when('update_post_meta')->alias(
            function (int $id, string $key, mixed $value): bool {
                $this->metaWrites[$id][$key] = $value;
                $this->postMeta[$id][$key]   = $value;
                return true;
            }
        );
    }

    public function test_fresh_screen_with_home_post_name_gets_namespaced_and_meta(): void
    {
        $this->addScreen(1, 100, 'home', 'kitchen-sink-demo');
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(1);

        self::assertSame('kitchen-sink-demo-home', $this->postsById[1]->post_name);
        self::assertSame('home', $this->postMeta[1]['_mua_screen_slug'] ?? null, 'Canonical slug must be stored in meta.');
    }

    public function test_wp_auto_suffixed_post_name_is_stripped_before_namespacing(): void
    {
        // WP auto-appended -3 at insert time because another post grabbed
        // "home" globally. Normaliser strips, then namespaces.
        $this->addScreen(1, 100, 'home-3', 'kitchen-sink-demo');
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(1);

        self::assertSame('kitchen-sink-demo-home', $this->postsById[1]->post_name);
        self::assertSame('home', $this->postMeta[1]['_mua_screen_slug'] ?? null);
    }

    public function test_legacy_post_name_with_app_prefix_is_recognised_as_home(): void
    {
        // A screen whose post_name is already app-prefixed (previous
        // ship) but is missing the new meta. Should infer "home" and
        // keep post_name stable.
        $this->addScreen(1, 100, 'kitchen-sink-demo-home', 'kitchen-sink-demo');
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(1);

        self::assertSame('kitchen-sink-demo-home', $this->postsById[1]->post_name);
        self::assertSame('home', $this->postMeta[1]['_mua_screen_slug'] ?? null);
    }

    public function test_same_app_screen_with_same_intended_slug_gets_numeric_suffix(): void
    {
        $this->addScreen(1, 100, 'home', 'app');
        $this->postMeta[1]['_mua_screen_slug'] = 'home';
        $this->postsById[1]->post_name = 'app-home';

        $this->addScreen(2, 100, 'home', 'app');
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(2);

        self::assertSame('app-home-2',   $this->postsById[2]->post_name);
        self::assertSame('home-2',       $this->postMeta[2]['_mua_screen_slug'] ?? null);
    }

    public function test_two_different_apps_can_both_own_home(): void
    {
        $this->addScreen(1, 100, 'home', 'app-one');
        $this->postMeta[1]['_mua_screen_slug'] = 'home';
        $this->postsById[1]->post_name = 'app-one-home';

        $this->addScreen(2, 200, 'home', 'app-two');
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(2);

        self::assertSame('app-two-home', $this->postsById[2]->post_name, 'Distinct apps must coexist with the same intended slug.');
        self::assertSame('home',         $this->postMeta[2]['_mua_screen_slug'] ?? null);
    }

    public function test_no_op_when_app_id_missing(): void
    {
        $this->addScreen(1, 0, 'home-3');

        Screen::normaliseSlugWithinApp(1);

        self::assertArrayNotHasKey(1, $this->renamed);
    }

    public function test_stale_suffixed_locked_slug_is_migrated(): void
    {
        // Pre-fix state: post_name = home-3, _mua_locked_slug = home-3
        // (both WP-suffixed). The new normaliser must migrate both.
        $this->addScreen(1, 100, 'home-3', 'kitchen-sink-demo');
        $this->postMeta[1]['_mua_locked_slug']        = 'home-3';
        $this->postMeta[100]['_mua_app_version_code'] = 6;
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(1);

        self::assertSame('kitchen-sink-demo-home', $this->postsById[1]->post_name);
        self::assertSame('home', $this->metaWrites[1]['_mua_locked_slug']  ?? null);
        self::assertSame('home', $this->metaWrites[1]['_mua_screen_slug'] ?? null);
    }

    public function test_rename_attempt_on_shipped_app_reverts_to_locked_slug(): void
    {
        // User tries to rename from "home" to "rebranded" after ship:
        // post_name has been changed upstream; intended diverges from
        // the lock. Normaliser must rebuild the namespaced post_name
        // against the locked (old) slug and emit a notice.
        $this->addScreen(1, 100, 'app-rebranded', 'app');
        $this->postMeta[1]['_mua_screen_slug']     = 'rebranded';
        $this->postMeta[1]['_mua_locked_slug']     = 'home';
        $this->postMeta[100]['_mua_last_build_at'] = '2026-04-19 10:00:00';
        Functions\when('get_current_user_id')->justReturn(7);
        $captured = [];
        Functions\when('set_transient')->alias(
            function (string $key, mixed $value) use (&$captured): bool {
                $captured[$key] = $value;
                return true;
            }
        );
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(1);

        self::assertSame('app-home', $this->postsById[1]->post_name);
        self::assertSame('home',     $this->postMeta[1]['_mua_screen_slug'] ?? null);
        self::assertArrayHasKey('mua_slug_lock_notice_7', $captured);
        self::assertSame('screen',   $captured['mua_slug_lock_notice_7']['scope']);
    }

    public function test_first_save_after_ship_records_locked_slug(): void
    {
        $this->addScreen(1, 100, 'home', 'app');
        $this->postMeta[100]['_mua_app_version_code'] = 2;
        $this->captureMetaWrites();

        Screen::normaliseSlugWithinApp(1);

        self::assertSame('home', $this->metaWrites[1]['_mua_locked_slug'] ?? null);
    }
}
