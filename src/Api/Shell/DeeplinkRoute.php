<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Content\DeeplinkResolver;
use MustUse\Pub\Data\Models\App;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /apps/{app_slug}/resolve-deeplink
 *
 * Resolves an incoming deep-link path to a screen and context.
 * The shell receives a deep link, sends the path here, and the pub
 * returns which screen to render with what data.
 *
 * Resolution is pub-side because the pub knows the publisher's content
 * mapping and URL structure. The shell is deliberately thin.
 */
final class DeeplinkRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_slug>[a-z0-9-]+)/resolve-deeplink', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ AppKeyAuth::class, 'verify' ],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$app  = App::findBySlug( $request->get_param( 'app_slug' ) );
		$path = $request->get_param( 'path' ) ?? '';

		if ( ! $app instanceof App ) {
			return new WP_REST_Response( [ 'error' => 'app_not_found' ], 404 );
		}

		if ( empty( $path ) ) {
			return new WP_REST_Response( [
				'error' => 'Missing required field: path',
			], 400 );
		}

		$resolver = new DeeplinkResolver();
		$result   = $resolver->resolve( $app, $path );

		if ( $result === NULL ) {
			return new WP_REST_Response( [
				'screen_id' => 'home',
				'context'   => [],
			], 200 );
		}

		return new WP_REST_Response( $result, 200 );
	}
}
