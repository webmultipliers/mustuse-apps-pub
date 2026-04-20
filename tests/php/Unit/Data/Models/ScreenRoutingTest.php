<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Data\Models;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Exercises the Phase 1 routing surface — CPT / multi-term / meta_query /
 * orderby / order all flow through `Screen::queryArgs()` into the manifest.
 */
final class ScreenRoutingTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value) => $value
        );
        Functions\when('sanitize_key')->alias(
            static fn ($v) => \is_string($v) ? \strtolower(\preg_replace('/[^a-z0-9_\\-]/', '', $v) ?? '') : ''
        );
        Functions\when('sanitize_text_field')->alias(
            static fn ($v) => \is_string($v) ? \trim($v) : ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_standard_archive_with_multi_term_and_meta_query_emits_full_args(): void
    {
        $this->postMeta[1] = [
            '_mua_app_id'             => 10,
            '_mua_screen_role'        => 'archive',
            '_mua_route_type'         => 'standard',
            '_mua_route_post_type'    => 'product',
            '_mua_route_taxonomy'     => 'product_cat',
            '_mua_route_term_ids'     => [ 12, 34 ],
            '_mua_route_meta_query'   => [
                [ 'key' => 'price', 'value' => '100', 'compare' => '>=' ],
            ],
            '_mua_route_orderby'      => 'title',
            '_mua_route_order'        => 'ASC',
        ];
        $screen = new Screen(WP_Post::make(['ID' => 1, 'post_name' => 'products', 'post_type' => Screen::POST_TYPE]));

        $args = $screen->queryArgs($this->makeApp());
        self::assertIsArray($args);

        self::assertSame('product', $args['post_type']);
        self::assertSame('title',   $args['orderby']);
        self::assertSame('ASC',     $args['order']);
        self::assertSame('product_cat', $args['tax_query'][0]['taxonomy']);
        self::assertSame([12, 34],      $args['tax_query'][0]['terms']);
        self::assertSame('IN',          $args['tax_query'][0]['operator']);
        self::assertSame('price', $args['meta_query'][0]['key']);
        self::assertSame('>=',    $args['meta_query'][0]['compare']);
    }

    public function test_sanitise_meta_query_rejects_unknown_compare_operators(): void
    {
        $sanitised = Screen::sanitiseMetaQuery([
            [ 'key' => 'featured', 'value' => '1', 'compare' => 'DROP TABLE' ],
        ]);

        self::assertSame('=', $sanitised[0]['compare'], 'Unknown comparators fall back to `=`.');
    }

    public function test_sanitise_meta_query_caps_at_ten_clauses(): void
    {
        $input = [];
        for ($i = 0; $i < 25; $i++) {
            $input[] = [ 'key' => 'k' . $i, 'value' => 'v', 'compare' => '=' ];
        }

        $sanitised = Screen::sanitiseMetaQuery($input);

        self::assertCount(10, $sanitised);
    }

    public function test_empty_term_ids_skip_tax_query_entirely(): void
    {
        $this->postMeta[1] = [
            '_mua_app_id'          => 10,
            '_mua_screen_role'     => 'archive',
            '_mua_route_type'      => 'standard',
            '_mua_route_post_type' => 'post',
            '_mua_route_taxonomy'  => 'category',
            '_mua_route_term_ids'  => [],
        ];
        $screen = new Screen(WP_Post::make(['ID' => 1, 'post_name' => 'feed', 'post_type' => Screen::POST_TYPE]));

        $args = $screen->queryArgs($this->makeApp());
        self::assertIsArray($args);

        self::assertArrayNotHasKey('tax_query', $args);
    }

    private function makeApp(): App
    {
        return new App(WP_Post::make([
            'ID' => 10, 'post_name' => 'app', 'post_title' => 'App', 'post_status' => 'publish',
        ]));
    }
}
