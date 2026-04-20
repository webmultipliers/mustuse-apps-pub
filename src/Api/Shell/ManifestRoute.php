<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Manifest\ManifestBuilder;
use MustUse\Pub\Manifest\ManifestValidator;
use MustUse\Pub\Support\ManifestSigner;
use WP_REST_Request;
use WP_REST_Response;

final class ManifestRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_slug>[a-z0-9-]+)/manifest', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ AppKeyAuth::class, 'verify' ],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$app = App::findBySlug( (string) $request->get_param( 'app_slug' ) );

		if ( ! $app instanceof App ) {
			return new WP_REST_Response( [ 'code' => 'mua_manifest_unavailable' ], 404 );
		}

		$cacheKey = 'mua_manifest_' . $app->id();
		$cached   = get_transient( $cacheKey );

		if ( \is_array( $cached ) && isset( $cached['manifest'], $cached['signature'] ) ) {
			$response = new WP_REST_Response( $cached['manifest'], 200 );
			$response->header( 'X-MUA-Signature', $cached['signature'] );
			return $response;
		}

		$manifest = ( new ManifestBuilder() )->build( $app );
		$errors   = ( new ManifestValidator() )->validate( $manifest, $app );

		if ( ! empty( $errors ) ) {
			/** @see mua_manifest_validation_failed action — extension hook for monitoring. */
			do_action( 'mua_manifest_validation_failed', $app->id(), $errors );

			return new WP_REST_Response( [
				'code'   => 'mua_manifest_invalid',
				'errors' => $errors,
			], 500 );
		}

		[ $manifest, $signature ] = ManifestSigner::sign( $manifest );
		set_transient(
			$cacheKey,
			[ 'manifest' => $manifest, 'signature' => $signature ],
			HOUR_IN_SECONDS
		);

		$response = new WP_REST_Response( $manifest, 200 );
		$response->header( 'X-MUA-Signature', $signature );
		return $response;
	}
}
