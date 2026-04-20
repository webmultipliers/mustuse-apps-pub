<?php

declare(strict_types=1);

namespace MustUse\Pub\Events;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use WP_Post;

/**
 * Hooks into WordPress lifecycle events to schedule manifest cache
 * invalidation and cascade-delete related data on app removal.
 *
 * For non-app / non-screen post saves, a cached post_type → app_ids map
 * avoids walking every app's routing on every unrelated save. The map is
 * invalidated whenever an app or screen saves (since routing changes can
 * add or remove post types).
 */
final class PostLifecycle {
	private const ROUTING_INDEX_OPTION = 'mua_post_type_routing_index';

	public static function listen(): void {
		add_action( 'save_post', [ self::class, 'onPostSaved' ], 10, 2 );
		add_action( 'before_delete_post', [ self::class, 'onBeforeDeletePost' ] );
		add_action( 'wp_trash_post', [ self::class, 'onAppTrashed' ] );
		add_action( 'untrashed_post', [ self::class, 'onAppUntrashed' ] );
		add_action( 'updated_option', [ self::class, 'onOptionUpdated' ], 10, 1 );
	}

	public static function onPostSaved( int $postId, WP_Post $post ): void {
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}

		// Screen save: invalidate only the owning app's manifest.
		if ( $post->post_type === Screen::POST_TYPE ) {
			self::clearRoutingIndex();
			$ownerAppId = (int) get_post_meta( $postId, '_mua_app_id', true );
			if ( $ownerAppId ) {
				as_schedule_single_action( \time(), 'mua_invalidate_manifest_cache', [ $ownerAppId ], 'mustuse-apps-pub' );
			}
			return;
		}

		// App save: invalidate that specific app's manifest + index.
		if ( $post->post_type === App::POST_TYPE ) {
			self::clearRoutingIndex();
			as_schedule_single_action( \time(), 'mua_invalidate_manifest_cache', [ $postId ], 'mustuse-apps-pub' );
			return;
		}

		// Any other content save: invalidate only apps whose routing
		// references this post's type. O(1) index lookup instead of
		// walking every app's routing on every save.
		$affected = self::appIdsForPostType( $post->post_type );
		foreach ( $affected as $appId ) {
			as_schedule_single_action(
				\time(),
				'mua_invalidate_manifest_cache',
				[ $appId ],
				'mustuse-apps-pub'
			);
		}
	}

	public static function onBeforeDeletePost( int $postId ): void {
		$post = get_post( $postId );

		if ( ! $post || $post->post_type !== App::POST_TYPE ) {
			return;
		}

		self::clearRoutingIndex();
		delete_transient( 'mua_manifest_' . $postId );

		foreach ( self::screensForApp( $postId, [ 'trash', 'publish', 'draft', 'pending', 'private', 'future', 'auto-draft' ] ) as $childId ) {
			wp_delete_post( $childId, true );
		}
	}

	public static function onAppTrashed( int $postId ): void {
		$post = get_post( $postId );
		if ( ! $post || $post->post_type !== App::POST_TYPE ) {
			return;
		}

		self::clearRoutingIndex();
		foreach ( self::screensForApp( $postId, [ 'publish', 'draft', 'pending', 'private', 'future' ] ) as $childId ) {
			wp_trash_post( $childId );
		}
	}

	public static function onAppUntrashed( int $postId ): void {
		$post = get_post( $postId );
		if ( ! $post || $post->post_type !== App::POST_TYPE ) {
			return;
		}

		self::clearRoutingIndex();
		foreach ( self::screensForApp( $postId, [ 'trash' ] ) as $childId ) {
			wp_untrash_post( $childId );
		}
	}

	/**
	 * @param string[] $statuses
	 * @return int[]
	 */
	private static function screensForApp( int $appId, array $statuses ): array {
		$children = get_posts( [
			'post_type'   => Screen::POST_TYPE,
			'numberposts' => -1,
			'post_status' => $statuses,
			'meta_key'    => '_mua_app_id',
			'meta_value'  => (string) $appId,
			'fields'      => 'ids',
		] );

		return \array_map( 'intval', $children );
	}

	/**
	 * Publisher-level brand changes affect every app's manifest assembly.
	 */
	public static function onOptionUpdated( string $option ): void {
		if ( $option !== 'mua_publisher_branding' ) {
			return;
		}
		$appIds = get_posts( [
			'post_type'   => App::POST_TYPE,
			'numberposts' => -1,
			'post_status' => 'publish',
			'fields'      => 'ids',
		] );
		foreach ( $appIds as $appId ) {
			as_schedule_single_action(
				\time(),
				'mua_invalidate_manifest_cache',
				[ (int) $appId ],
				'mustuse-apps-pub'
			);
		}
	}

	/**
	 * Returns the list of app ids whose routing references $postType.
	 * Builds and caches the index on first call; invalidated on app /
	 * screen save.
	 *
	 * @return int[]
	 */
	private static function appIdsForPostType( string $postType ): array {
		if ( $postType === '' ) {
			return [];
		}

		$index = get_option( self::ROUTING_INDEX_OPTION );
		if ( ! \is_array( $index ) ) {
			$index = self::buildRoutingIndex();
			update_option( self::ROUTING_INDEX_OPTION, $index, false );
		}

		$ids = $index[ $postType ] ?? [];
		return \is_array( $ids ) ? \array_map( 'intval', $ids ) : [];
	}

	/**
	 * Walk every app once and record which post types its routing uses.
	 *
	 * @return array<string, int[]>
	 */
	private static function buildRoutingIndex(): array {
		$appPosts = get_posts( [
			'post_type'   => App::POST_TYPE,
			'numberposts' => -1,
			'post_status' => 'publish',
			'fields'      => 'ids',
		] );

		$index = [];
		foreach ( $appPosts as $appId ) {
			$app = App::find( (int) $appId );
			if ( ! $app ) {
				continue;
			}
			$routing = \MustUse\Pub\Routing\ScreenRouteProjector::projectFor( $app )['routing'];
			foreach ( $routing as $route ) {
				$type = (string) ( $route['post_type'] ?? '' );
				if ( $type === '' ) {
					continue;
				}
				$index[ $type ][] = (int) $appId;
			}
		}

		foreach ( $index as $type => $ids ) {
			$index[ $type ] = \array_values( \array_unique( $ids ) );
		}

		return $index;
	}

	private static function clearRoutingIndex(): void {
		delete_option( self::ROUTING_INDEX_OPTION );
	}
}
