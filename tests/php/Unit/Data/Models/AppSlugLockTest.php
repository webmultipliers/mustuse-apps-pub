<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Data\Models;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class AppSlugLockTest extends TestCase
{
    /** @var array<int, WP_Post> */
    private array $posts = [];
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        Functions\when('get_post')->alias(
            fn (int $id): ?WP_Post => $this->posts[$id] ?? null
        );
        Functions\when('get_post_meta')->alias(
            function (int $id, string $key) {
                return $this->postMeta[$id][$key] ?? '';
            }
        );
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('set_transient')->alias(
            function (string $key, mixed $value): bool {
                $this->transients[$key] = $value;
                return true;
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    private function addApp(int $id, string $slug, array $meta = []): void
    {
        $this->posts[$id] = WP_Post::make([
            'ID'          => $id,
            'post_name'   => $slug,
            'post_title'  => \ucfirst($slug),
            'post_status' => 'publish',
        ]);
        $this->posts[$id]->post_type = App::POST_TYPE;
        $this->postMeta[$id] = $meta;
    }

    public function test_slug_change_allowed_when_app_has_never_shipped(): void
    {
        $this->addApp(42, 'news');

        $data = App::lockSlugAfterFirstShip(
            ['post_type' => App::POST_TYPE, 'post_name' => 'newspaper'],
            ['ID' => 42]
        );

        self::assertSame('newspaper', $data['post_name']);
        self::assertSame([], $this->transients);
    }

    public function test_slug_change_reverted_when_app_has_been_shipped(): void
    {
        $this->addApp(42, 'news', ['_mua_last_build_at' => '2026-04-19 10:00:00']);

        $data = App::lockSlugAfterFirstShip(
            ['post_type' => App::POST_TYPE, 'post_name' => 'newspaper'],
            ['ID' => 42]
        );

        self::assertSame('news', $data['post_name']);
        self::assertArrayHasKey('mua_slug_lock_notice_7', $this->transients);
        self::assertSame('news', $this->transients['mua_slug_lock_notice_7']['kept']);
    }

    public function test_slug_change_reverted_when_version_code_advanced(): void
    {
        $this->addApp(42, 'news', ['_mua_app_version_code' => 3]);

        $data = App::lockSlugAfterFirstShip(
            ['post_type' => App::POST_TYPE, 'post_name' => 'newspaper'],
            ['ID' => 42]
        );

        self::assertSame('news', $data['post_name']);
    }

    public function test_passes_through_other_post_types(): void
    {
        $data = App::lockSlugAfterFirstShip(
            ['post_type' => 'post', 'post_name' => 'something'],
            ['ID' => 99]
        );

        self::assertSame('something', $data['post_name']);
    }

    public function test_no_op_for_brand_new_inserts(): void
    {
        $data = App::lockSlugAfterFirstShip(
            ['post_type' => App::POST_TYPE, 'post_name' => 'fresh'],
            ['ID' => 0]
        );

        self::assertSame('fresh', $data['post_name']);
    }
}
