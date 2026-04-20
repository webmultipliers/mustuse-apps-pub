<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Logging\ShellAccessLog;
use MustUse\Pub\Manifest\CapabilityRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /apps/{app_slug}/capabilities
 *
 * Shells advertise their native capabilities and supported block types
 * at first contact so the pub can tailor manifests accordingly.
 *
 * Expected payload shape:
 *   {
 *       "app_type": "mobile_ios",
 *       "shell_version": "1.0.0",
 *       "native_capabilities": { "camera": true, "biometrics": true, ... },
 *       "component_registry": ["core/paragraph", "core/heading", "mustuse-apps-pub/hero", ...]
 *   }
 */
final class CapabilityRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_slug>[a-z0-9-]+)/capabilities', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ AppKeyAuth::class, 'verify' ],
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$app = App::findBySlug( (string) $request->get_param( 'app_slug' ) );

		if ( ! $app instanceof App ) {
			return new WP_REST_Response( [ 'code' => 'mua_app_unavailable' ], 404 );
		}

		$body = $request->get_json_params();
		if ( ! \is_array( $body ) ) {
			return new WP_REST_Response( [ 'code' => 'mua_capability_invalid' ], 400 );
		}

		if ( ! self::validateAdvertisement( $body ) ) {
			return new WP_REST_Response( [ 'code' => 'mua_capability_invalid_shape' ], 400 );
		}

		ShellAccessLog::record( 'capability_advertisement', [
			'app_id'        => $app->id(),
			'app_type'      => $body['app_type'] ?? 'unknown',
			'shell_version' => $body['shell_version'] ?? 'unknown',
		] );

		$currentVersion  = $app->meta( 'shell_version', '0.0.0' );
		$incomingVersion = (string) ( $body['shell_version'] ?? '0.0.0' );

		if ( \version_compare( $incomingVersion, $currentVersion, '>=' ) ) {
			CapabilityRegistry::record( $app, $body );
			$app->updateMeta( 'shell_version', $incomingVersion );

			// Manifest filters blocks against advertised capabilities. A
			// changed advertisement must invalidate the cached manifest or
			// the shell keeps seeing stale requiredCapabilities for an hour.
			if ( \function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'mua_invalidate_manifest_cache',
					[ $app->id() ],
					'mustuse-apps-pub'
				);
			} else {
				delete_transient( 'mua_manifest_' . $app->id() );
			}
		}

		return new WP_REST_Response( [
			'status' => 'recorded',
		], 200 );
	}

	/**
	 * Shape allowlist for block names: `namespace/blockname`, each side
	 * lowercase-alphanumeric with optional hyphens. Stored advertisement
	 * data is surfaced to admin screens; escaping at render is still
	 * correct, but refusing obviously hostile shapes at ingest is
	 * cheap defence-in-depth.
	 */
	private const BLOCK_NAME_PATTERN = '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/';

	private const MAX_NATIVE_CAPABILITIES = 64;
	private const MAX_COMPONENT_REGISTRY  = 256;

	/**
	 * Validate the shell-contributed payload against the advertisement
	 * schema (contracts/pub-shell/capability-advertisement.md) so a
	 * compromised shell can't drive persistence of arbitrary shapes.
	 */
	private static function validateAdvertisement( array $body ): bool {
		$appType = $body['app_type'] ?? NULL;
		if ( ! \is_string( $appType ) || ! \preg_match( '/^[a-z_]{3,32}$/', $appType ) ) {
			return false;
		}

		$version = $body['shell_version'] ?? NULL;
		if ( ! \is_string( $version ) || ! \preg_match( '/^\d{1,3}(\.\d{1,3}){0,3}$/', $version ) ) {
			return false;
		}

		if ( isset( $body['native_capabilities'] ) ) {
			if ( ! \is_array( $body['native_capabilities'] ) ) {
				return false;
			}
			if ( \count( $body['native_capabilities'] ) > self::MAX_NATIVE_CAPABILITIES ) {
				return false;
			}
			foreach ( $body['native_capabilities'] as $capability => $value ) {
				// Keys must be known capabilities.
				// Values are booleans today; accept true/false or stringly
				// truthy values (some shells send "true"/"1").
				if ( ! \is_string( $capability ) || ! CapabilityRegistry::isKnown( $capability ) ) {
					return false;
				}
				if ( ! \is_scalar( $value ) ) {
					return false;
				}
			}
		}

		if ( isset( $body['component_registry'] ) ) {
			if ( ! \is_array( $body['component_registry'] ) ) {
				return false;
			}
			if ( \count( $body['component_registry'] ) > self::MAX_COMPONENT_REGISTRY ) {
				return false;
			}
			foreach ( $body['component_registry'] as $componentName ) {
				if ( ! \is_string( $componentName ) || ! \preg_match( self::BLOCK_NAME_PATTERN, $componentName ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
