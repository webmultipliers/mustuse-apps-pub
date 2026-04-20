<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Content\ContentMapper;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_slug}/content
 *
 * Returns WordPress content mapped to this app's screen structure.
 *
 * Query params: `id`, `post_type`, `page`, `per_page`, `search`,
 * `category`, `taxonomy`, `term`, `author`, `orderby`, `order`.
 *
 * Response contract: `{ items, pagination, app }` (see ContentMapper).
 * Response header `X-MUA-Content-Revision` carries the app's monotonic
 * content-invalidation token — shells can compare against their cached
 * value and skip a body read when nothing changed.
 */
final class ContentRoute
{
    private const NAMESPACE   = 'mustuse-apps-pub/v1';
    private const DEFAULT_TTL = 60;

    /**
     * Params that legitimately affect the response. Unknown params are
     * dropped before hashing so junk query strings can't inflate cache-
     * key cardinality.
     */
    private const CACHEABLE_PARAMS = [
        'id', 'post_type', 'page', 'per_page', 'search',
        'category', 'taxonomy', 'term', 'author',
        'orderby', 'order', 'exclude',
    ];

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_slug>[a-z0-9-]+)/content', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [AppKeyAuth::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $app = App::findBySlug($request->get_param('app_slug'));

        if (! $app) {
            return new WP_REST_Response(['error' => 'app_not_found'], 404);
        }

        $params   = $request->get_params();
        $revision = (string) $app->meta('cache_rev_content', '0');

        // Server-side cache is intentionally short (default 60s) — it exists
        // to absorb traffic spikes, not as the primary freshness layer. The
        // shell runs its own longer-TTL SWR cache keyed by the revision
        // token below; when publishers invalidate content they bump
        // `_mua_cache_rev_content` and every shell's next request sees fresh
        // bytes without any explicit flush here.
        $ttl = (int) apply_filters('mua_content_cache_ttl', self::DEFAULT_TTL, $app);
        $key = self::cacheKey($app->id(), $revision, $params);

        $cached = wp_cache_get($key, 'mua_content');
        if ($cached !== false && \is_array($cached)) {
            return $this->respondWith($cached, $revision);
        }

        $content = (new ContentMapper())->mapForApp($app, $params);

        if ($ttl > 0) {
            wp_cache_set($key, $content, 'mua_content', $ttl);
        }

        return $this->respondWith($content, $revision);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function respondWith(array $body, string $revision): WP_REST_Response
    {
        $response = new WP_REST_Response($body, 200);
        $response->header('X-MUA-Content-Revision', $revision);
        $response->header('Cache-Control', 'private, max-age=0, must-revalidate');
        return $response;
    }

    /**
     * Cache key includes the revision token so bumping `_mua_cache_rev_content`
     * naturally invalidates every existing entry without a separate flush.
     *
     * @param array<string,mixed> $params
     */
    private static function cacheKey(int $appId, string $revision, array $params): string
    {
        $filtered = \array_intersect_key($params, \array_flip(self::CACHEABLE_PARAMS));
        \ksort($filtered);
        return 'c:' . $appId . ':' . $revision . ':' . \sha1((string) wp_json_encode($filtered));
    }
}
