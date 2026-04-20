<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\WellKnown;

use MustUse\Pub\Data\Models\App;

/**
 * GET /.well-known/assetlinks.json (at the domain root)
 *
 * Serves the Android Asset Links file for app links. Google
 * fetches from a fixed path at the domain root — NOT from the REST API
 * mount point (/wp-json/). Earlier code registered this via
 * `register_rest_route('', '/.well-known/...')` which mounted the route
 * at `/wp-json//.well-known/...` and could never satisfy Google's verifier.
 *
 * We listen on `init` (fires on every request, before rewrite resolution),
 * match REQUEST_URI, emit the payload and exit. No auth — Google fetches
 * unauthenticated.
 */
final class AndroidAssetLinks {
	private const PATH = '/.well-known/assetlinks.json';

	/** Rate limit for unauthenticated public endpoint hits per IP per minute. */
	private const RATE_LIMIT_PER_MINUTE = 60;

	public function register(): void {
		add_action( 'init', [ $this, 'listen' ], 5 );
	}

	public function listen(): void {
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( $path !== self::PATH ) {
			return;
		}

		if ( ! WellKnownThrottle::allow( 'android-assetlinks', $_SERVER['REMOTE_ADDR'] ?? '', self::RATE_LIMIT_PER_MINUTE ) ) {
			status_header( 429 );
			\header( 'Retry-After: 60' );
			\header( 'Content-Type: application/json' );
			echo wp_json_encode( [ 'error' => 'rate_limited' ] );
			exit;
		}

		status_header( 200 );
		\header( 'Content-Type: application/json' );
		\header( 'Cache-Control: public, max-age=86400' );
		echo wp_json_encode( self::buildPayload() );
		exit;
	}

	/**
	 * Pure payload builder — separated so integration tests can exercise
	 * the contract shape without having to trap `exit` from `listen()`.
	 *
	 * @return array<int, array{relation:array<int,string>, target:array{namespace:string, package_name:string, sha256_cert_fingerprints:array<int,string>}}>
	 */
	public static function buildPayload(): array {
		$currentHost = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$statements  = [];

		foreach ( self::getAppsForHostStatic( $currentHost ) as $app ) {
			$packageName = (string) $app->meta( 'android_package_name', '' );
			if ( $packageName === '' ) {
				continue;
			}

			$fingerprints = $app->meta( 'android_sha256_fingerprints', [] );
			$statements[] = [
				'relation' => [ 'delegate_permission/common.handle_all_urls' ],
				'target'   => [
					'namespace'                => 'android_app',
					'package_name'             => $packageName,
					'sha256_cert_fingerprints' => (array) $fingerprints,
				],
			];
		}

		return $statements;
	}

	/** @return App[] */
	private static function getAppsForHostStatic( string $host ): array {
		$matched = [];
		foreach ( get_posts( [
			'post_type'      => App::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		] ) as $post ) {
			$app = new App( $post );
			if ( (string) $app->meta( 'deeplink_host', '' ) === $host ) {
				$matched[] = $app;
			}
		}
		return $matched;
	}
}
