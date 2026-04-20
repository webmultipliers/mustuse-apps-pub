<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Editorial;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Jobs\ProjectBuildToGithub;
use MustUse\Pub\Manifest\ManifestBuilder;
use MustUse\Pub\Support\BuildAssembler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /apps/{id}/ship — the "Ship It!" build dispatch.
 *
 * Assembles the build locally and enqueues the GitHub projection as an Action
 * Scheduler job. The endpoint returns immediately so the UI can poll
 * BuildStatusRoute for progress.
 */
final class ShipRoute {
	private const NAMESPACE = 'mustuse-apps-pub/v1';

	public function register(): void {
		register_rest_route( self::NAMESPACE , '/apps/(?P<app_id>\d+)/ship', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ $this, 'authorize' ],
			'args'                => [
				'app_id' => [
					'validate_callback' => static fn( $v ) => \is_numeric( $v ),
				],
			],
		] );
	}

	public const SHIP_CAPABILITY = 'mua_ship_app';

	public function authorize( WP_REST_Request $request ): bool {
		$appId = (int) $request->get_param( 'app_id' );

		if ( $appId <= 0 ) {
			return false;
		}

		return current_user_can( self::SHIP_CAPABILITY, $appId ) || current_user_can( 'manage_options' );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$appId = (int) $request->get_param( 'app_id' );
		$app   = App::find( $appId );

		if ( ! $app ) {
			return new WP_Error(
				'mua_ship_unavailable',
				__( 'Unable to process build request.', 'mustuse-apps-pub' ),
				[ 'status' => 403 ]
			);
		}

		$gitRepoUrl = (string) $app->meta( 'build_repo_url', '' );
		if ( $gitRepoUrl === '' ) {
			return new WP_Error( 'mua_ship_error', __( 'Build repository URL is not configured for this app.', 'mustuse-apps-pub' ), [ 'status' => 500 ] );
		}

		if ( (string) $app->meta( 'github_token', '' ) === '' ) {
			return new WP_Error( 'mua_ship_error', __( 'GitHub token is not configured for this app.', 'mustuse-apps-pub' ), [ 'status' => 500 ] );
		}

		if ( ! \function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'mua_ship_error', __( 'Action Scheduler is not available.', 'mustuse-apps-pub' ), [ 'status' => 500 ] );
		}

		// Reject if a projection for this app is already in flight. Without
		// this guard, two rapid clicks race to produce the same branch name
		// and the second job overwrites the first's "projected" status with
		// a "Reference already exists" failure.
		if ( \function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( ProjectBuildToGithub::ACTION, null, 'mustuse-apps-pub' ) ) {
			return new WP_Error(
				'mua_ship_in_flight',
				__( 'A build is already queued for this publisher. Wait for it to finish, then try again.', 'mustuse-apps-pub' ),
				[ 'status' => 409 ]
			);
		}

		$uploadDir     = wp_upload_dir();
		$buildsBase    = $uploadDir['basedir'] . '/mua-builds';
		$buildPath     = $buildsBase . '/' . $app->slug();
		$blueprintPath = WP_PLUGIN_DIR . '/mustuse-apps-pub/assets/blueprints/mobile-shell';

		if ( ! \is_dir( $buildsBase ) && ! \mkdir( $buildsBase, 0755, true ) && ! \is_dir( $buildsBase ) ) {
			return new WP_Error(
				'mua_ship_error',
				__( 'Could not create build directory. Check upload-dir permissions.', 'mustuse-apps-pub' ),
				[ 'status' => 500 ]
			);
		}
		if ( \file_put_contents( $buildsBase . '/.htaccess', 'deny from all' ) === false ) {
			return new WP_Error(
				'mua_ship_error',
				__( 'Could not write the build directory access guard.', 'mustuse-apps-pub' ),
				[ 'status' => 500 ]
			);
		}

		$assembler    = new BuildAssembler();
		$manifest     = ( new ManifestBuilder() )->build( $app );
		$manifestJson = (string) \json_encode( $manifest, JSON_PRETTY_PRINT );

		$assembler->assembleBase( $app, $manifestJson, $blueprintPath, $buildPath );

		$envForBifrost = $assembler->envForBifrost( $app );

		// Jitter the branch name so clock regression or second-boundary
		// collisions can't produce two branches with the same ref.
		$suffix        = \bin2hex( \random_bytes( 3 ) );
		$branch        = 'build/' . $app->slug() . '-' . \time() . '-' . $suffix;
		$commitMessage = \sprintf( '[mua] Project build for app %d (%s)', $app->id(), $app->slug() );

		update_post_meta( $app->id(), '_mua_last_build_status', 'pending' );
		update_post_meta( $app->id(), '_mua_last_build_branch', $branch );
		update_post_meta( $app->id(), '_mua_last_build_at', current_time( 'mysql', true ) );
		delete_post_meta( $app->id(), '_mua_last_build_error' );
		delete_post_meta( $app->id(), '_mua_last_build_commit' );
		delete_post_meta( $app->id(), '_mua_last_build_compare_url' );

		as_enqueue_async_action(
			ProjectBuildToGithub::ACTION,
			[ $app->id(), $buildPath, $branch, $commitMessage ],
			'mustuse-apps-pub'
		);

		// Bump after the projection is safely queued so a failed assemble or
		// queue call leaves version_code untouched and the publisher can retry
		// the same ship without skipping a build number.
		$assembler->bumpVersionCode( $app );

		return new WP_REST_Response( [
			'success'         => true,
			'status'          => 'pending',
			'branch'          => $branch,
			'message'         => __( 'Build queued for projection. Poll /build-status for progress.', 'mustuse-apps-pub' ),
			'env_for_bifrost' => $envForBifrost,
		], 202 );
	}
}
