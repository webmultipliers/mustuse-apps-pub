<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Content\ContextBuilder;
use MustUse\Pub\Content\DeviceStateStore;
use MustUse\Pub\Data\Models\App;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * /apps/{slug}/history — GET (list), POST (record visit).
 *
 * Device-scoped via X-MUA-Device-Id. Recording a visit upserts the post
 * to the front of the reverse-chron list; 500-entry cap per device.
 */
final class HistoryRoute
{
    private const NAMESPACE = 'mustuse-apps-pub/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_slug>[a-z0-9-]+)/history', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleList'],
                'permission_callback' => [AppKeyAuth::class, 'verify'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handleRecord'],
                'permission_callback' => [AppKeyAuth::class, 'verify'],
            ],
        ]);
    }

    public function handleList(WP_REST_Request $request): WP_REST_Response
    {
        [$app, $deviceId, $err] = $this->resolve($request);
        if ($err !== null || ! $app instanceof App) {
            return $err ?? new WP_REST_Response(['error' => 'app_not_found'], 404);
        }
        return new WP_REST_Response(['items' => $this->hydrate(DeviceStateStore::listHistory($app, $deviceId))], 200);
    }

    public function handleRecord(WP_REST_Request $request): WP_REST_Response
    {
        [$app, $deviceId, $err] = $this->resolve($request);
        if ($err !== null || ! $app instanceof App) {
            return $err ?? new WP_REST_Response(['error' => 'app_not_found'], 404);
        }
        $postId   = (int) $request->get_param('post_id');
        $postType = (string) ($request->get_param('post_type') ?? 'post');
        if ($postId <= 0) {
            return new WP_REST_Response(['error' => 'invalid_post_id'], 400);
        }
        DeviceStateStore::recordVisit($app, $deviceId, $postId, $postType);
        return new WP_REST_Response(['recorded' => true], 200);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrate(array $rows): array
    {
        $hydrated = [];
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                continue;
            }
            $post = get_post((int) ($row['post_id'] ?? 0));
            if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
                continue;
            }
            $hydrated[] = [
                'post'       => ContextBuilder::postContext($post, true),
                'visited_at' => $row['visited_at'] ?? null,
            ];
        }
        return $hydrated;
    }

    /**
     * @return array{0: ?App, 1: string, 2: ?WP_REST_Response}
     */
    private function resolve(WP_REST_Request $request): array
    {
        $app = App::findBySlug((string) $request->get_param('app_slug'));
        if (! $app) {
            return [null, '', new WP_REST_Response(['error' => 'app_not_found'], 404)];
        }
        $deviceId = DeviceStateStore::deviceIdFromHeader($request->get_header('X-MUA-Device-Id'));
        if ($deviceId === '') {
            return [null, '', new WP_REST_Response(['error' => 'missing_device_id'], 400)];
        }
        return [$app, $deviceId, null];
    }
}
