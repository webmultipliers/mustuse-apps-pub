<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Preview;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Preview\PreviewRoute;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Covers the testable surface of PreviewRoute: the URL rewriter that
 * Gutenberg's Preview button hits, and the helper that builds a fresh
 * preview URL with a valid nonce.
 *
 * The dispatch path (`maybeRender`) ends with `exit` after rendering a
 * full HTML document, so it lives outside unit-testing reach — its
 * behavior is verified by integration smoke tests that boot wp-env.
 */
final class PreviewRouteTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();

        Functions\when('wp_create_nonce')->alias(
            static fn (string $action): string => 'nonce-for-' . $action
        );
        Functions\when('home_url')->alias(
            static fn (string $path = ''): string => 'https://pub.test' . $path
        );
        Functions\when('add_query_arg')->alias(
            static function (array $args, string $url): string {
                $sep = \str_contains($url, '?') ? '&' : '?';
                $parts = [];
                foreach ($args as $k => $v) {
                    $parts[] = \rawurlencode($k) . '=' . \rawurlencode((string) $v);
                }
                return $url . $sep . \implode('&', $parts);
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_previewUrlFor_includes_post_id_and_nonce(): void
    {
        $url = PreviewRoute::previewUrlFor(42);

        self::assertStringContainsString('mua_preview=42', $url);
        self::assertStringContainsString('preview_nonce=nonce-for-post_preview_42', $url);
        self::assertStringStartsWith('https://pub.test/', $url);
    }

    public function test_filterPreviewLink_rewrites_for_app_posts(): void
    {
        $post = WP_Post::make(['ID' => 7, 'post_type' => App::POST_TYPE]);
        $rewritten = PreviewRoute::filterPreviewLink('https://pub.test/?p=7&preview=true', $post);

        self::assertStringContainsString('mua_preview=7', $rewritten);
        self::assertStringContainsString('preview_nonce=nonce-for-post_preview_7', $rewritten);
        self::assertStringNotContainsString('preview=true', $rewritten);
    }

    public function test_filterPreviewLink_rewrites_for_screen_posts(): void
    {
        $post = WP_Post::make(['ID' => 11, 'post_type' => Screen::POST_TYPE]);
        $rewritten = PreviewRoute::filterPreviewLink('https://pub.test/?p=11&preview=true', $post);

        self::assertStringContainsString('mua_preview=11', $rewritten);
    }

    public function test_filterPreviewLink_passes_through_unrelated_post_types(): void
    {
        $post     = WP_Post::make(['ID' => 99, 'post_type' => 'post']);
        $original = 'https://pub.test/sample-post?preview=true';

        self::assertSame($original, PreviewRoute::filterPreviewLink($original, $post));
    }
}
