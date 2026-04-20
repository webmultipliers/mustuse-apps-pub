<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Editorial;

use MustUse\Pub\Admin\DeploymentRequirements;
use MustUse\Pub\Data\Models\App;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_id}/deployment-requirements
 *
 * Returns the current deployment-readiness checks for the Deployment Map.
 * Same auth gate as ShipRoute — the map is on the same editor screen.
 */
final class DeploymentRequirementsRoute
{
    private const NAMESPACE = 'mustuse-apps-pub/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_id>\d+)/deployment-requirements', [
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

    public function authorize(WP_REST_Request $request): bool
    {
        $appId = (int) $request->get_param('app_id');
        if ($appId <= 0) {
            return false;
        }
        return current_user_can(ShipRoute::SHIP_CAPABILITY, $appId)
            || current_user_can('manage_options');
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $app = App::find((int) $request->get_param('app_id'));
        if (! $app) {
            return new WP_Error('mua_not_found', __('App not found.', 'mustuse-apps-pub'), ['status' => 404]);
        }

        return new WP_REST_Response([
            'checks' => DeploymentRequirements::checksForApp($app),
        ], 200);
    }
}
