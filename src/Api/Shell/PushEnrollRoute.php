<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Content\DeviceStateStore;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /apps/{slug}/push/enroll — body: `{ token, platform }`.
 *
 * Device-scoped via X-MUA-Device-Id. Publishers hook
 * `mua_device_state_push_enroll` to forward the token to their provider
 * (Firebase, OneSignal, etc.). Token length capped to guard against
 * oversized writes into the per-device option blob.
 */
final class PushEnrollRoute
{
    private const NAMESPACE       = 'mustuse-apps-pub/v1';
    private const MAX_TOKEN_CHARS = 2048;

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_slug>[a-z0-9-]+)/push/enroll', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [AppKeyAuth::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $app = App::findBySlug((string) $request->get_param('app_slug'));
        if (! $app) {
            return new WP_REST_Response(['error' => 'app_not_found'], 404);
        }
        $deviceId = DeviceStateStore::deviceIdFromHeader($request->get_header('X-MUA-Device-Id'));
        if ($deviceId === '') {
            return new WP_REST_Response(['error' => 'missing_device_id'], 400);
        }

        $token    = (string) $request->get_param('token');
        $platform = (string) ($request->get_param('platform') ?? 'unknown');

        if ($token === '' || \strlen($token) > self::MAX_TOKEN_CHARS) {
            return new WP_REST_Response(['error' => 'invalid_token'], 400);
        }
        if (! \in_array($platform, ['ios', 'android', 'web'], true)) {
            return new WP_REST_Response(['error' => 'invalid_platform'], 400);
        }

        $record = DeviceStateStore::enrollPushToken($app, $deviceId, $token, $platform);
        return new WP_REST_Response(['enrolled' => true, 'enrolled_at' => $record['enrolled_at'] ?? null], 200);
    }
}
