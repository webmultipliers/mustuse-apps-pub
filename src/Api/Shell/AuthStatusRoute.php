<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_slug}/auth/status
 *
 * Returns the current subscriber_state or authenticated: false.
 */
final class AuthStatusRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_slug>[a-z0-9-]+)/auth/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ AppKeyAuth::class, 'verify' ],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$app      = App::findBySlug( $request->get_param( 'app_slug' ) );
		$deviceId = sanitize_text_field( $request->get_header( 'X-MUA-Device-Id' ) ?? '' );

		/**
		 * Filter: retrieve subscriber state for the current device/session.
		 *
		 * @param array|null $subscriberState Null if not authenticated.
		 * @param string     $deviceId        The device identifier from the shell.
		 * @param App|null   $app             The app being queried.
		 */
		$subscriberState = apply_filters( 'mua_auth_get_status', NULL, $deviceId, $app );

		if ( $subscriberState === NULL ) {
			return new WP_REST_Response( [
				'authenticated' => false,
			], 200 );
		}

		return new WP_REST_Response( [
			'authenticated'    => true,
			'subscriber_state' => $subscriberState,
		], 200 );
	}
}
