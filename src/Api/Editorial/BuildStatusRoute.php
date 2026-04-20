<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Editorial;

use MustUse\Pub\Data\Models\App;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_id}/build-status — live build progress for the "Ship It!"
 * terminal pane.
 *
 * No streaming infrastructure yet (no SSE, no long-poll). The editorial
 * bundle polls this at a low cadence (~3s) until `status` reaches a
 * terminal state. Data is sourced from the `_mua_last_build_*` meta
 * that ProjectBuildToGithub writes — so the endpoint is just a thin
 * serializer over the post meta with the same auth gate as ShipRoute.
 */
final class BuildStatusRoute
{
    private const NAMESPACE = 'mustuse-apps-pub/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_id>\d+)/build-status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
            'args'                => [
                'app_id' => [
                    'validate_callback' => static fn ($v) => \is_numeric($v),
                ],
            ],
        ]);
    }

    /**
     * Same gate as ShipRoute — the build terminal is the Ship It button's
     * UI so they must share the authorization surface. Read-only polling
     * from a lower-privileged reader could leak build version / error
     * strings, which is why we don't soften the auth here.
     */
    public function authorize(WP_REST_Request $request): bool
    {
        $appId = (int) $request->get_param('app_id');
        if ($appId <= 0) {
            return false;
        }

        if (current_user_can(ShipRoute::SHIP_CAPABILITY, $appId)) {
            return true;
        }
        return current_user_can('manage_options');
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $appId = (int) $request->get_param('app_id');
        $app   = App::find($appId);

        if (! $app) {
            // Mirror ShipRoute's opaque-error shape so reads can't enumerate
            // app IDs via 404-vs-403 distinctions.
            return new WP_Error(
                'mua_ship_unavailable',
                __('Unable to read build status.', 'mustuse-apps-pub'),
                ['status' => 403]
            );
        }

        $id         = $app->id();
        $status     = (string) get_post_meta($id, '_mua_last_build_status', true);
        $version    = (string) get_post_meta($id, '_mua_last_build_version', true);
        $buildId    = (string) get_post_meta($id, '_mua_last_build_id', true);
        $builtAt    = (string) get_post_meta($id, '_mua_last_build_at', true);
        $error      = (string) get_post_meta($id, '_mua_last_build_error', true);
        $branch     = (string) get_post_meta($id, '_mua_last_build_branch', true);
        $commit     = (string) get_post_meta($id, '_mua_last_build_commit', true);
        $compareUrl = (string) get_post_meta($id, '_mua_last_build_compare_url', true);
        $historyRaw = get_post_meta($id, '_mua_ship_history', true);
        $history    = \is_array($historyRaw) ? $historyRaw : [];

        return new WP_REST_Response([
            'status'      => $status !== '' ? $status : 'idle',
            'version'     => $version,
            'build_id'    => $buildId,
            'at'          => $builtAt,
            'error'       => $error,
            'branch'      => $branch,
            'commit'      => $commit,
            'compare_url' => $compareUrl,
            'history'     => $history,
            // Terminal state drives the frontend's stop-polling decision.
            // `projected` / `unchanged` = pub finished its side (success or no-op).
            'terminal'    => \in_array($status, ['projected', 'unchanged', 'failed'], true),
        ], 200);
    }
}
