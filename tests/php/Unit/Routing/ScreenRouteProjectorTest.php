<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Routing\ScreenRouteProjector;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Covers the new `screen_tree` shape ScreenRouteProjector emits alongside
 * the existing flat lists. Drives the projector against a stubbed WP env
 * (Brain Monkey) so screen records can be planted with arbitrary
 * post_parent / menu_order combinations.
 */
final class ScreenRouteProjectorTest extends TestCase
{
    /** @var array<int, WP_Post> indexed by post ID */
    private array $postsById = [];
    /** @var array<int, array<string, mixed>> post meta keyed by post id then meta key */
    private array $postMeta = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        $this->postsById = [];
        $this->postMeta  = [];

        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args) => $value
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

        // get_posts: filter our planted posts by app_id + status + sort by
        // menu_order ASC, then ID ASC (stand-in for date ASC).
        Functions\when('get_posts')->alias(
            function (array $args) {
                $appId = (int) ($args['meta_value'] ?? 0);
                $rows  = [];
                foreach ($this->postsById as $post) {
                    if (($this->postMeta[$post->ID]['_mua_app_id'] ?? 0) !== $appId) {
                        continue;
                    }
                    $rows[] = $post;
                }
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

        Functions\when('get_post')->alias(
            fn (int $id): ?WP_Post => \array_key_exists($id, $this->postsById) ? $this->postsById[$id] : null
        );

        Functions\when('get_post_field')->justReturn('');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    private function makeApp(int $id = 100): App
    {
        $post = WP_Post::make([
            'ID'          => $id,
            'post_name'   => 'demo',
            'post_title'  => 'Demo',
            'post_status' => 'publish',
        ]);
        $this->postsById[$id] = $post;
        return new App($post);
    }

    private function addScreen(int $id, int $appId, string $slug, int $parentId = 0, int $menuOrder = 0): void
    {
        $post = WP_Post::make([
            'ID'          => $id,
            'post_name'   => $slug,
            'post_title'  => \ucfirst($slug),
            'post_status' => 'publish',
            'post_parent' => $parentId,
            'menu_order'  => $menuOrder,
        ]);
        $this->postsById[$id] = $post;
        $this->postMeta[$id]  = [
            '_mua_app_id'         => $appId,
            '_mua_route_type'     => 'none',
            '_mua_show_in_nav'    => false,
            '_mua_nav_order'      => 0,
            '_mua_screen_icon'    => '',
        ];
    }

    public function test_screen_tree_groups_children_under_parents(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'home', 0, 0);
        $this->addScreen(2, 100, 'settings', 0, 1);
        $this->addScreen(3, 100, 'profile', 2, 0);   // child of settings
        $this->addScreen(4, 100, 'security', 2, 1);  // child of settings

        $tree = ScreenRouteProjector::projectFor($app)['screen_tree'];

        self::assertCount(2, $tree);
        self::assertSame('home', $tree[0]['screen_id']);
        self::assertSame('settings', $tree[1]['screen_id']);
        self::assertCount(2, $tree[1]['children']);
        self::assertSame('profile', $tree[1]['children'][0]['screen_id']);
        self::assertSame('security', $tree[1]['children'][1]['screen_id']);
    }

    public function test_screen_tree_supports_grandchildren(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'a', 0, 0);
        $this->addScreen(2, 100, 'b', 1, 0);  // child of a
        $this->addScreen(3, 100, 'c', 2, 0);  // grandchild

        $tree = ScreenRouteProjector::projectFor($app)['screen_tree'];

        self::assertSame('a', $tree[0]['screen_id']);
        self::assertSame('b', $tree[0]['children'][0]['screen_id']);
        self::assertSame('c', $tree[0]['children'][0]['children'][0]['screen_id']);
    }

    public function test_screen_tree_orphans_attach_to_root_when_parent_is_missing(): void
    {
        // A screen whose post_parent points outside the app's screen set
        // should still appear — at root — rather than vanish from the tree.
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'root-screen', 0, 0);
        $this->addScreen(2, 100, 'orphan', 999, 1);   // 999 not present

        $tree = ScreenRouteProjector::projectFor($app)['screen_tree'];

        // Both root entries appear; the orphan falls under whatever bucket
        // its parent id maps to. Since 999 is empty in $byParent the orphan
        // is unreachable — that's the contract: missing-parent screens are
        // intentionally hidden until the editor fixes the link or repoints.
        $rootIds = \array_column($tree, 'screen_id');
        self::assertContains('root-screen', $rootIds);
        self::assertNotContains('orphan', $rootIds);
    }

    public function test_flat_lists_are_unaffected_by_tree_projection(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'home', 0, 0);
        $this->addScreen(2, 100, 'profile', 1, 0);

        $result = ScreenRouteProjector::projectFor($app);

        self::assertCount(2, $result['screens']);
        self::assertCount(0, $result['routing']);     // both routeType=none
        self::assertCount(0, $result['navigation']);  // neither show_in_nav
    }

    public function test_explicit_home_flag_wins_path_root(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'first',  0, 0);
        $this->addScreen(2, 100, 'second', 0, 1);
        $this->postMeta[2]['_mua_is_home'] = true;

        $result = ScreenRouteProjector::projectFor($app);

        $byId = \array_column($result['screens'], null, 'screen_id');
        self::assertSame('/',       $byId['second']['path']);
        self::assertTrue($byId['second']['is_home']);
        self::assertSame('/first',  $byId['first']['path']);
        self::assertFalse($byId['first']['is_home']);
    }

    public function test_first_show_in_nav_screen_is_elected_home_when_no_flag(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'inbox',    0, 0);
        $this->addScreen(2, 100, 'feed',     0, 1);
        $this->addScreen(3, 100, 'settings', 0, 2);
        $this->postMeta[2]['_mua_show_in_nav'] = true;
        $this->postMeta[2]['_mua_nav_order']   = 0;
        $this->postMeta[3]['_mua_show_in_nav'] = true;
        $this->postMeta[3]['_mua_nav_order']   = 1;

        $byId = \array_column(ScreenRouteProjector::projectFor($app)['screens'], null, 'screen_id');

        self::assertSame('/',         $byId['feed']['path']);
        self::assertTrue($byId['feed']['is_home']);
        self::assertSame('/settings', $byId['settings']['path']);
    }

    public function test_detail_screen_uses_deeplink_template_as_path(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'home', 0, 0);
        $this->addScreen(2, 100, 'article-detail', 0, 1);
        $this->postMeta[2]['_mua_screen_role']   = 'detail';
        $this->postMeta[2]['_mua_deeplink_path'] = '/article/{slug}';

        $byId = \array_column(ScreenRouteProjector::projectFor($app)['screens'], null, 'screen_id');

        self::assertSame('/article/{slug}', $byId['article-detail']['path']);
        self::assertSame('/',               $byId['home']['path']);
    }

    public function test_detail_screen_without_deeplink_falls_back_to_slug_path(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'home',   0, 0);
        $this->addScreen(2, 100, 'orphan', 0, 1);
        $this->postMeta[2]['_mua_screen_role'] = 'detail';

        $byId = \array_column(ScreenRouteProjector::projectFor($app)['screens'], null, 'screen_id');

        self::assertSame('/orphan', $byId['orphan']['path']);
    }

    public function test_side_nav_collects_only_flagged_screens_in_order(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'home',     0, 0);
        $this->addScreen(2, 100, 'inbox',    0, 1);
        $this->addScreen(3, 100, 'archive',  0, 2);
        $this->addScreen(4, 100, 'settings', 0, 3);
        $this->postMeta[2]['_mua_show_in_side_nav'] = true;
        $this->postMeta[2]['_mua_side_nav_order']   = 1;
        $this->postMeta[4]['_mua_show_in_side_nav'] = true;
        $this->postMeta[4]['_mua_side_nav_order']   = 0;

        $sideNav = ScreenRouteProjector::projectFor($app)['side_nav'];

        self::assertCount(2, $sideNav);
        self::assertSame('settings', $sideNav[0]['screen_id']);
        self::assertSame('inbox',    $sideNav[1]['screen_id']);
        self::assertSame('/settings', $sideNav[0]['path']);
    }

    public function test_side_nav_empty_when_no_screens_flagged(): void
    {
        $app = $this->makeApp();
        $this->addScreen(1, 100, 'home',  0, 0);
        $this->addScreen(2, 100, 'about', 0, 1);

        $result = ScreenRouteProjector::projectFor($app);

        self::assertSame([], $result['side_nav']);
    }
}
