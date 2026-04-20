<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Data\Models\App;
use WP_Comment;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_slug}/comments/{post_id}
 *
 * Returns the approved comment thread for a post, paginated and nested.
 * Only fires when the App has `_mua_enable_comments=true` AND the post's
 * own comment_status is `open` — same gate WP's front-end honours.
 *
 * Query params: `per_page` (default 50, max 200), `offset` (default 0).
 */
final class CommentsRoute
{
    private const NAMESPACE = 'mustuse-apps-pub/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_slug>[a-z0-9-]+)/comments/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [AppKeyAuth::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $appSlug = (string) $request->get_param('app_slug');
        $postId  = (int) $request->get_param('post_id');

        $app = App::findBySlug($appSlug);
        if (! $app instanceof App) {
            return new WP_REST_Response(['error' => 'app_not_found'], 404);
        }

        $enabled = (bool) $app->meta('enable_comments', false);
        if (! $enabled) {
            return new WP_REST_Response([
                'post_id' => $postId,
                'enabled' => false,
                'items'   => [],
                'total'   => 0,
            ], 200);
        }

        $post = \function_exists('get_post') ? get_post($postId) : null;
        if (! $post || $post->comment_status !== 'open') {
            return new WP_REST_Response([
                'post_id' => $postId,
                'enabled' => true,
                'open'    => false,
                'items'   => [],
                'total'   => 0,
            ], 200);
        }

        $perPage = \max(1, \min(200, (int) ($request->get_param('per_page') ?: 50)));
        $offset  = \max(0, (int) ($request->get_param('offset') ?: 0));

        $raw = \function_exists('get_comments') ? get_comments([
            'post_id' => $postId,
            'status'  => 'approve',
            'number'  => $perPage,
            'offset'  => $offset,
            'orderby' => 'comment_date',
            'order'   => 'ASC',
            'parent'  => 0,
        ]) : [];

        $items = [];
        if (\is_array($raw)) {
            foreach ($raw as $comment) {
                if ($comment instanceof WP_Comment) {
                    $items[] = self::serialiseComment($comment);
                }
            }
        }

        $total = \function_exists('get_comments') ? (int) get_comments([
            'post_id' => $postId,
            'status'  => 'approve',
            'count'   => true,
        ]) : 0;

        return new WP_REST_Response([
            'post_id' => $postId,
            'enabled' => true,
            'open'    => true,
            'items'   => $items,
            'total'   => $total,
        ], 200, [
            'Cache-Control' => 'public, max-age=' . (int) apply_filters('mua_comments_cache_ttl', 120),
        ]);
    }

    /** @return array<string, mixed> */
    private static function serialiseComment(WP_Comment $comment): array
    {
        $authorAvatar = '';
        if (\function_exists('get_avatar_url')) {
            $authorAvatar = (string) get_avatar_url((string) $comment->comment_author_email, ['size' => 96]);
        }

        $children = [];
        if (\function_exists('get_comments')) {
            $replies = get_comments([
                'parent'  => (int) $comment->comment_ID,
                'status'  => 'approve',
                'orderby' => 'comment_date',
                'order'   => 'ASC',
            ]);
            if (\is_array($replies)) {
                foreach ($replies as $reply) {
                    if ($reply instanceof WP_Comment) {
                        $children[] = self::serialiseComment($reply);
                    }
                }
            }
        }

        // Comments are public-user input. WP's moderation pipeline
        // runs kses on submission, but defense-in-depth: re-sanitise
        // through `wp_kses_post` here so any path that skipped
        // moderation (CLI imports, rogue admin inserts, old content)
        // still lands as safe HTML on the device. Then `wpautop` for
        // paragraph wrapping.
        $raw       = (string) $comment->comment_content;
        $sanitised = \function_exists('wp_kses_post') ? (string) wp_kses_post($raw) : \strip_tags($raw);
        $content   = \function_exists('wpautop') ? (string) wpautop($sanitised) : $sanitised;

        return [
            'id'       => (int) $comment->comment_ID,
            'author'   => (string) $comment->comment_author,
            'avatar'   => $authorAvatar,
            'date'     => (string) $comment->comment_date,
            'content'  => $content,
            'children' => $children,
        ];
    }
}
