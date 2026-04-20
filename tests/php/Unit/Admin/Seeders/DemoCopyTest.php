<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Admin\Seeders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Admin\Seeders\DemoCopy;
use PHPUnit\Framework\TestCase;

/**
 * Pins the demo-copy fixture shape so the seeder can trust the data
 * without defensive checks everywhere. Pure-data tests — no WP
 * functions required.
 */
final class DemoCopyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_categories_are_all_demo_prefixed_and_unique(): void
    {
        $slugs = \array_map(static fn (array $c): string => $c['slug'], DemoCopy::categories());
        foreach ($slugs as $slug) {
            self::assertStringStartsWith('demo-', $slug, "Category slug '{$slug}' must be demo-prefixed so reset can sweep it.");
        }
        self::assertSame($slugs, \array_unique($slugs));
        self::assertGreaterThanOrEqual(5, \count($slugs));
    }

    public function test_tags_are_all_demo_prefixed_and_unique(): void
    {
        $slugs = \array_map(static fn (array $t): string => $t['slug'], DemoCopy::tags());
        foreach ($slugs as $slug) {
            self::assertStringStartsWith('demo-', $slug);
        }
        self::assertSame($slugs, \array_unique($slugs));
    }

    public function test_every_post_points_at_a_real_seeded_category(): void
    {
        $catSlugs = \array_map(static fn (array $c): string => $c['slug'], DemoCopy::categories());
        foreach (DemoCopy::posts() as $post) {
            self::assertContains(
                $post['category'],
                $catSlugs,
                "Post '{$post['slug']}' points at category '{$post['category']}' which isn't seeded."
            );
        }
    }

    public function test_every_post_tag_is_a_real_seeded_tag(): void
    {
        $tagSlugs = \array_map(static fn (array $t): string => $t['slug'], DemoCopy::tags());
        foreach (DemoCopy::posts() as $post) {
            foreach ($post['tags'] as $tag) {
                self::assertContains(
                    $tag,
                    $tagSlugs,
                    "Post '{$post['slug']}' references tag '{$tag}' which isn't seeded."
                );
            }
        }
    }

    public function test_posts_have_unique_slugs(): void
    {
        $slugs = \array_map(static fn (array $p): string => $p['slug'], DemoCopy::posts());
        self::assertSame($slugs, \array_unique($slugs));
        self::assertGreaterThanOrEqual(15, \count($slugs));
    }

    public function test_compose_body_emits_gutenberg_block_comments(): void
    {
        $body = DemoCopy::composeBody([
            [ 'type' => 'p', 'text' => 'Hello.' ],
            [ 'type' => 'h', 'text' => 'Heading' ],
        ]);

        self::assertStringContainsString('<!-- wp:paragraph -->', $body);
        self::assertStringContainsString('<!-- /wp:paragraph -->', $body);
        self::assertStringContainsString('<!-- wp:heading {"level":3} -->', $body);
        self::assertStringContainsString('<!-- /wp:heading -->', $body);
    }
}
