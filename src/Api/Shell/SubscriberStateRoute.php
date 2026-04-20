<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_slug}/subscriber/state
 *
 * Refreshes and returns the subscriber_state object.
 * Shells call this to get the latest entitlement/subscription status.
 */
final class SubscriberStateRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_slug>[a-z0-9-]+)/subscriber/state', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ AppKeyAuth::class, 'verify' ],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$app      = App::findBySlug( $request->get_param( 'app_slug' ) );
		$deviceId = sanitize_text_field( $request->get_header( 'X-MUA-Device-Id' ) ?? '' );

		/**
		 * Filter: refresh and return the subscriber state.
		 *
		 * @param array|null $subscriberState Null if no active subscription.
		 * @param string     $deviceId        The device identifier from the shell.
		 * @param App|null   $app             The app being queried.
		 */
		$subscriberState = apply_filters( 'mua_subscriber_refresh_state', NULL, $deviceId, $app );

		if ( $subscriberState === NULL ) {
			return new WP_REST_Response( [
				'has_subscription' => false,
			], 200 );
		}

		return new WP_REST_Response( [
			'has_subscription' => true,
			'subscriber_state' => $subscriberState,
		], 200 );
	}
}
