<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /apps/{app_slug}/auth/logout
 *
 * Clears the session state for the current device.
 */
final class AuthLogoutRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_slug>[a-z0-9-]+)/auth/logout', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ AppKeyAuth::class, 'verify' ],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$app      = App::findBySlug( $request->get_param( 'app_slug' ) );
		$deviceId = sanitize_text_field( $request->get_header( 'X-MUA-Device-Id' ) ?? '' );

		if ( ! $app instanceof App ) {
			// Don't enumerate which slugs exist — return the same shape as
			// a successful logout so probes can't distinguish.
			return new WP_REST_Response( [ 'authenticated' => false ], 200 );
		}

		/**
		 * Action: clear auth session for a device.
		 *
		 * @param string $deviceId The device identifier from the shell.
		 * @param App    $app      The app being logged out of.
		 */
		do_action( 'mua_auth_logout', $deviceId, $app );

		return new WP_REST_Response( [
			'authenticated' => false,
		], 200 );
	}
}
