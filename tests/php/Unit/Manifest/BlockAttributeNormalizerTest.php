<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Manifest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Manifest\BlockAttributeNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Post-Phase-A contract: the normalizer resolves REFERENCES
 * (image attrs → rich shape, hero CTA fields → cta object) and
 * otherwise passes attributes through untouched. It no longer queries
 * WP for article lists, category lists, author cards, or single posts —
 * that work moved to runtime `/content` fetches on the shell
 * (see Phase B `DynamicBlock`).
 */
final class BlockAttributeNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('apply_filters')->alias(static fn ($hook, $value) => $value);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_non_array_attributes_are_coerced_to_empty_array(): void
    {
        $result = BlockAttributeNormalizer::normalize('junk', 'mustuse-apps-pub/hero', 1);
        self::assertSame([], $result);
    }

    public function test_image_attribute_from_attachment_id_resolves_full_shape(): void
    {
        Functions\when('wp_get_attachment_image_url')->justReturn('https://cdn.example.com/big.jpg');
        Functions\when('wp_get_attachment_metadata')->justReturn(['width' => 1200, 'height' => 800]);
        Functions\when('get_post_meta')->justReturn('Alt text');

        $result = BlockAttributeNormalizer::normalize(
            ['imageUrl' => ['id' => 42, 'url' => 'fallback.jpg']],
            'mustuse-apps-pub/hero',
            1,
        );

        self::assertIsArray($result['imageUrl']);
        self::assertSame(42, $result['imageUrl']['id']);
        self::assertSame('https://cdn.example.com/big.jpg', $result['imageUrl']['url']);
        self::assertSame('Alt text', $result['imageUrl']['alt']);
        self::assertSame(1200, $result['imageUrl']['width']);
        self::assertSame(800, $result['imageUrl']['height']);
    }

    public function test_image_attribute_from_raw_url_string_passes_through(): void
    {
        $result = BlockAttributeNormalizer::normalize(
            ['image' => 'https://example.com/direct.jpg'],
            'mustuse-apps-pub/image',
            1,
        );

        self::assertSame([
            'id'     => 0,
            'url'    => 'https://example.com/direct.jpg',
            'alt'    => '',
            'width'  => 0,
            'height' => 0,
        ], $result['image']);
    }

    public function test_hero_folds_cta_target_fields_into_single_cta_object(): void
    {
        $result = BlockAttributeNormalizer::normalize(
            [
                'heading'        => 'Welcome',
                'ctaLabel'       => 'Get started',
                'ctaTargetType'  => 'screen',
                'ctaTargetValue' => 'onboarding',
            ],
            'mustuse-apps-pub/hero',
            1,
        );

        self::assertSame('Get started', $result['cta']['label']);
        self::assertSame('screen', $result['cta']['type']);
        self::assertSame('/onboarding', $result['cta']['url']);
    }

    public function test_hero_returns_null_cta_when_label_or_target_missing(): void
    {
        $result = BlockAttributeNormalizer::normalize(
            ['heading' => 'x', 'ctaTargetType' => 'none', 'ctaTargetValue' => ''],
            'mustuse-apps-pub/hero',
            1,
        );
        self::assertNull($result['cta']);
    }

    public function test_resolve_image_back_compat_shim_still_works(): void
    {
        // Downstream plugins that call BlockAttributeNormalizer::resolveImage()
        // should keep working; the impl just delegates to ImageSerializer now.
        self::assertNull(BlockAttributeNormalizer::resolveImage(''));
        self::assertNull(BlockAttributeNormalizer::resolveImage(null));
        self::assertNull(BlockAttributeNormalizer::resolveImage([]));
        self::assertNull(BlockAttributeNormalizer::resolveImage(['id' => 0, 'url' => '']));
    }

    public function test_article_list_attributes_pass_through_untouched(): void
    {
        // Pre-Phase-A this block baked get_posts() results into the manifest.
        // Now the manifest carries only the query intent; items come from a
        // live /content call on the shell.
        $attrs  = ['postType' => 'post', 'count' => 10, 'category' => 3];
        $result = BlockAttributeNormalizer::normalize($attrs, 'mustuse-apps-pub/article-list', 1);

        self::assertSame($attrs, $result);
        self::assertArrayNotHasKey('items', $result, 'items must NOT be baked into manifest');
    }

    public function test_article_card_attributes_pass_through_untouched(): void
    {
        $attrs  = ['postId' => 42, 'showExcerpt' => true];
        $result = BlockAttributeNormalizer::normalize($attrs, 'mustuse-apps-pub/article-card', 1);

        self::assertSame($attrs, $result);
        self::assertArrayNotHasKey('article', $result, 'article must NOT be baked into manifest');
    }

    public function test_category_pills_attributes_pass_through_untouched(): void
    {
        $attrs  = ['taxonomy' => 'category', 'count' => 8, 'autoFromPopular' => true];
        $result = BlockAttributeNormalizer::normalize($attrs, 'mustuse-apps-pub/category-pills', 1);

        self::assertSame($attrs, $result);
        self::assertArrayNotHasKey('items', $result);
    }

    public function test_author_card_attributes_pass_through_untouched(): void
    {
        $attrs  = ['authorId' => 7, 'showBio' => true];
        $result = BlockAttributeNormalizer::normalize($attrs, 'mustuse-apps-pub/author-card', 1);

        self::assertSame($attrs, $result);
        self::assertArrayNotHasKey('author', $result);
    }
}
