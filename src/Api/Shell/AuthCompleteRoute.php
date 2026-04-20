<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /apps/{app_slug}/auth/complete
 *
 * Accepts a token from the shell's authentication flow and returns
 * the subscriber_state object.
 */
final class AuthCompleteRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_slug>[a-z0-9-]+)/auth/complete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ AppKeyAuth::class, 'verify' ],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$app   = App::findBySlug( $request->get_param( 'app_slug' ) );
		$token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );

		if ( ! $app instanceof App ) {
			return new WP_REST_Response( [ 'code' => 'mua_app_unavailable' ], 404 );
		}

		if ( $token === '' ) {
			return new WP_REST_Response( [
				'code'    => 'mua_auth_missing_token',
				'message' => 'A token is required to complete authentication.',
			], 400 );
		}

		/**
		 * Filter: resolve a token into a subscriber state.
		 *
		 * Third-party auth plugins (Leaky Paywall, MemberPress) hook here
		 * to look up the user behind the token and return their state.
		 *
		 * @param array|null $subscriberState Null if unresolved.
		 * @param string     $token           The auth token from the shell.
		 * @param App        $app             The app being authenticated against.
		 */
		$subscriberState = apply_filters( 'mua_auth_resolve_token', NULL, $token, $app );

		if ( $subscriberState === NULL ) {
			return new WP_REST_Response( [
				'code'    => 'mua_auth_invalid_token',
				'message' => 'The provided token could not be resolved.',
			], 401 );
		}

		return new WP_REST_Response( [
			'authenticated'    => true,
			'subscriber_state' => $subscriberState,
		], 200 );
	}
}
