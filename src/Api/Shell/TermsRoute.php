<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Content\TermsMapper;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_slug}/terms
 *
 * Returns a list of taxonomy terms for chip/pill rows and archive panels.
 * Query params: `taxonomy` (default `category`), `per_page`, `include`
 * (csv of ids), `include_empty` (0|1), `orderby` (`count|name|slug|term_id`),
 * `order` (`ASC|DESC`).
 *
 * Same auth + cache-header contract as ContentRoute. TTL defaults to
 * 30 minutes because terms move rarely; filter `mua_terms_cache_ttl`
 * to override.
 */
final class TermsRoute
{
    private const NAMESPACE   = 'mustuse-apps-pub/v1';
    private const DEFAULT_TTL = 1800;

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_slug>[a-z0-9-]+)/terms', [
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

        $ttl = (int) apply_filters('mua_terms_cache_ttl', self::DEFAULT_TTL, $app);
        $key = 't:' . $app->id() . ':' . $revision . ':' . \sha1((string) wp_json_encode($params));

        $cached = wp_cache_get($key, 'mua_content');
        if ($cached !== false && \is_array($cached)) {
            return $this->respondWith($cached, $revision);
        }

        $body = (new TermsMapper())->mapForApp($app, $params);

        if ($ttl > 0) {
            wp_cache_set($key, $body, 'mua_content', $ttl);
        }
        return $this->respondWith($body, $revision);
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
}
