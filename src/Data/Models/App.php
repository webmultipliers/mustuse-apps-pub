<?php

declare(strict_types=1);

namespace MustUse\Pub\Data\Models;

use WP_Post;

final class App {
	public const POST_TYPE = 'mua_app';

	private WP_Post $post;

	public function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	public static function registerPostType(): void {
		register_post_type( self::POST_TYPE, [
			'labels'              => [
				'name'               => __( 'Apps', 'mustuse-apps-pub' ),
				'singular_name'      => __( 'App', 'mustuse-apps-pub' ),
				'menu_name'          => __( 'Apps', 'mustuse-apps-pub' ),
				'add_new'            => __( 'Add New', 'mustuse-apps-pub' ),
				'add_new_item'       => __( 'Add New App', 'mustuse-apps-pub' ),
				'edit_item'          => __( 'Edit App', 'mustuse-apps-pub' ),
				'new_item'           => __( 'New App', 'mustuse-apps-pub' ),
				'view_item'          => __( 'Preview App', 'mustuse-apps-pub' ),
				'search_items'       => __( 'Search Apps', 'mustuse-apps-pub' ),
				'not_found'          => __( 'No apps yet.', 'mustuse-apps-pub' ),
				'not_found_in_trash' => __( 'No apps in trash.', 'mustuse-apps-pub' ),
				'all_items'          => __( 'All Apps', 'mustuse-apps-pub' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'mustuse-apps',
			'show_in_rest'        => true,
			'supports'            => [ 'title', 'editor', 'page-attributes', 'revisions' ],
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
			'template'            => self::defaultCanvasTemplate(),
			'template_lock'       => false,
		] );

		add_filter( 'wp_insert_post_data', [ self::class, 'lockSlugAfterFirstShip' ], 10, 2 );
	}

	/**
	 * Once an app has been shipped, the slug is baked into store bundle ids
	 * (`com.mustuse.{slug}`) and into manifest URLs the device fetches.
	 * Renaming it after first ship orphans every install, so we hold the
	 * post_name steady and surface a notice on the next admin page load.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public static function lockSlugAfterFirstShip( array $data, array $postarr ): array {
		if ( ( $data['post_type'] ?? '' ) !== self::POST_TYPE ) {
			return $data;
		}
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id <= 0 ) {
			return $data;
		}
		$existing = get_post( $id );
		if ( ! $existing || $existing->post_name === '' ) {
			return $data;
		}
		if ( ! self::hasShipped( $id ) ) {
			return $data;
		}
		if ( ( $data['post_name'] ?? '' ) === $existing->post_name ) {
			return $data;
		}

		$data['post_name'] = $existing->post_name;
		set_transient( 'mua_slug_lock_notice_' . get_current_user_id(), [
			'app_id' => $id,
			'kept'   => $existing->post_name,
		], 60 );
		return $data;
	}

	private static function hasShipped( int $appId ): bool {
		$built = (string) get_post_meta( $appId, '_mua_last_build_at', true );
		if ( $built !== '' ) {
			return true;
		}
		return (int) get_post_meta( $appId, '_mua_app_version_code', true ) > 1;
	}

	/**
	 * Default canvas arrangement.
	 *
	 * The block editor inserts this template the first time an app post
	 * is opened. Publishers can rearrange or remove blocks; the template
	 * is not locked. Extensions can contribute additional blocks via
	 * the `mua_canvas_blocks` filter.
	 *
	 * @return array<int, array{0:string,1?:array<string,mixed>}>
	 */
	private static function defaultCanvasTemplate(): array {
		$blocks = [
			[ 'mustuse-apps-pub-canvas/branding-preview', [] ],
			[ 'mustuse-apps-pub-canvas/mobile-preview', [] ],
			[ 'mustuse-apps-pub-canvas/navigation-preview', [] ],
			[ 'mustuse-apps-pub-canvas/manifest-preview', [] ],
			[ 'mustuse-apps-pub-canvas/capability-status', [] ],
		];

		/** @see mua_canvas_blocks filter. */
		return (array) apply_filters( 'mua_canvas_blocks', $blocks );
	}

	public static function find( int $id ): ?self {
		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || $post->post_type !== self::POST_TYPE ) {
			return NULL;
		}

		return new self( $post );
	}

	public static function findBySlug( string $slug ): ?self {
		$posts = get_posts( [
			'post_type'   => self::POST_TYPE,
			'name'        => $slug,
			'numberposts' => 1,
			'post_status' => 'publish',
		] );

		return ! empty( $posts ) ? new self( $posts[0] ) : NULL;
	}

	/**
	 * Create a new app post.
	 */
	public static function create( string $title, string $appType = 'mobile_ios' ): ?self {
		// No `$wp_error = true` second arg → wp_insert_post returns 0 on failure
		// and never returns WP_Error here, so the `is_wp_error` branch is dead.
		$postId = wp_insert_post( [
			'post_type'   => self::POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'draft',
		] );

		if ( $postId === 0 ) {
			return NULL;
		}

		// Set the protected meta manually
		update_post_meta( $postId, '_mua_app_type', $appType );

		return self::find( $postId );
	}

	public function id(): int {
		return $this->post->ID;
	}

	public function slug(): string {
		// WordPress only auto-generates post_name when a post is published.
		// Drafts end up with an empty post_name, which would make every
		// per-app URL (`/apps//manifest`) and the bundle ID
		// (`com.mustuse.`) invalid. Fall back to a sanitized title so
		// publishers can ship drafts for testing without a broken build.
		$stored = (string) $this->post->post_name;
		if ( $stored !== '' ) {
			return $stored;
		}
		$fromTitle = sanitize_title( (string) $this->post->post_title );
		if ( $fromTitle !== '' ) {
			return $fromTitle;
		}
		return 'app-' . (string) $this->post->ID;
	}

	public function title(): string {
		return $this->post->post_title;
	}

	/**
	 * Retrieve app-scoped metadata. All keys are prefixed with `_mua_` in storage.
	 */
	public function meta( string $key, mixed $default = NULL ): mixed {
		$value = get_post_meta( $this->post->ID, '_mua_' . $key, true );

		return $value !== '' ? $value : $default;
	}

	public function updateMeta( string $key, mixed $value ): void {
		update_post_meta( $this->post->ID, '_mua_' . $key, $value );
	}

	// -- App type ---------------------------------------------------------

	public const MOBILE_TYPES = [ 'mobile_ios', 'mobile_android' ];

	public const DESKTOP_TYPES = [ 'desktop_macos', 'desktop_windows', 'desktop_linux' ];

	public const ALL_TYPES = [ ...self::MOBILE_TYPES, ...self::DESKTOP_TYPES ];

	/**
	 * Default when a new app is created. NativePHP targets both iOS and
	 * Android from a single source, so the default is "both platforms"
	 * rather than picking one.
	 *
	 * @var string[]
	 */
	public const DEFAULT_APP_TYPES = self::MOBILE_TYPES;

	/**
	 * Canonical multi-target API. A single app can target multiple
	 * platforms (iOS + Android) because NativePHP handles the build. The
	 * legacy singular `_mua_app_type` meta is still read as a migration
	 * fallback so existing installs don't lose their configuration.
	 *
	 * @return string[]
	 */
	public function appTypes(): array {
		$stored = $this->meta( 'app_types', NULL );
		if ( \is_array( $stored ) ) {
			$clean = \array_values( \array_unique( \array_filter(
				$stored,
				static fn( $t ) => \is_string( $t ) && self::isValidAppType( $t )
			) ) );
			if ( $clean !== [] ) {
				return $clean;
			}
		}

		// Legacy migration: read the old singular meta if present. Never
		// writes — setAppTypes() handles the canonical write on next save.
		$legacy = $this->meta( 'app_type', '' );
		if ( \is_string( $legacy ) && $legacy !== '' ) {
			return [ $legacy ];
		}

		return self::DEFAULT_APP_TYPES;
	}

	/**
	 * Write the target platform list. Values are deduped and validated;
	 * unknown or desktop types are filtered out silently so the UI can't
	 * persist an illegal shape.
	 *
	 * @param string[] $types
	 */
	public function setAppTypes( array $types ): void {
		// Declared as `string[]`, so the `is_string` check would be
		// redundant per the signature — filter on the validity check only.
		$valid = \array_values( \array_unique( \array_filter(
			$types,
			static fn( string $t ) => self::isValidAppType( $t )
		) ) );
		$this->updateMeta( 'app_types', $valid );
	}

	/**
	 * Back-compat accessor. Callers that only need a single "primary"
	 * platform (e.g. env-var tagging, the per-shell manifest field) still
	 * work unchanged. New code should prefer {@see appTypes}.
	 */
	public function appType(): string {
		$types = $this->appTypes();
		return $types[0] ?? 'mobile_ios';
	}

	/**
	 * Back-compat setter. Prefer {@see setAppTypes}.
	 */
	public function setAppType( string $type ): void {
		$this->setAppTypes( [ $type ] );
	}

	public static function isValidAppType( string $type ): bool {
		return \in_array( $type, self::ALL_TYPES, true );
	}

	public static function isSupportedAppType( string $type ): bool {
		return \in_array( $type, self::MOBILE_TYPES, true );
	}

	/**
	 * Validate a full app-type list for publish. Empty lists are rejected
	 * (an app needs at least one platform) and every entry must be a
	 * supported mobile type — desktop targets are v2.
	 *
	 * @param string[] $types
	 */
	public static function isSupportedAppTypes( array $types ): bool {
		if ( $types === [] ) {
			return false;
		}
		foreach ( $types as $type ) {
			if ( ! \is_string( $type ) || ! self::isSupportedAppType( $type ) ) {
				return false;
			}
		}
		return true;
	}
}
