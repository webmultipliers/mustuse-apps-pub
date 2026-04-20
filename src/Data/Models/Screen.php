<?php

declare(strict_types=1);

namespace MustUse\Pub\Data\Models;

use WP_Post;

/**
 * Wraps the `mua_app_screen` custom post type.
 *
 * Screens are app-scoped surfaces authored in the WordPress block editor.
 * The block tree is serialized into the manifest's `screens` key.
 */
final class Screen {
	public const POST_TYPE = 'mua_app_screen';

	private WP_Post $post;

	public function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	public static function registerPostType(): void {
		register_post_type( self::POST_TYPE, [
			'labels'             => [
				'name'          => __( 'App Screens', 'mustuse-apps-pub' ),
				'singular_name' => __( 'Screen', 'mustuse-apps-pub' ),
				'add_new_item'  => __( 'Add New Screen', 'mustuse-apps-pub' ),
				'edit_item'     => __( 'Edit Screen', 'mustuse-apps-pub' ),
			],
			'public'             => false,
			'publicly_queryable' => false,
			'exclude_from_search' => true,
			'hierarchical'       => true,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'show_in_rest'       => true,
			'supports'           => [ 'title', 'editor', 'page-attributes' ],
			'capability_type'    => 'page',
			'map_meta_cap'       => true,
		] );

		$authCallback = static function ( bool $allowed, string $metaKey, int $postId ): bool {
			return current_user_can( 'edit_post', $postId );
		};

		register_post_meta( self::POST_TYPE, '_mua_app_id', [
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_screen_icon', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_key',
			'auth_callback'     => $authCallback,
		] );

		// Canonical per-app screen slug. `post_name` is forced to
		// `{app-slug}-{screen-slug}` to sidestep WP's global hierarchical
		// uniqueness check, but everything publisher-facing (manifest,
		// routes, shell) reads this meta so the user-intended value (e.g.
		// "home") is what surfaces. See `normaliseSlugWithinApp()` for
		// the write path.
		register_post_meta( self::POST_TYPE, '_mua_screen_slug', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_title',
			'auth_callback'     => $authCallback,
		] );

		// The role determines which other route metas are meaningful:
		// `static` renders blocks only; `archive` uses the `_mua_route_*`
		// metas below to drive a query; `detail` uses `_mua_deeplink_path`
		// to pattern-match an incoming URL and resolve a single post.
		register_post_meta( self::POST_TYPE, '_mua_screen_role', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'static',
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $value ): string {
				$value = \is_string( $value ) ? $value : '';
				return \in_array( $value, [ 'static', 'archive', 'detail' ], true ) ? $value : 'static';
			},
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_route_type', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'none',
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $value ): string {
				$value = \is_string( $value ) ? $value : '';
				return \in_array( $value, [ 'none', 'standard', 'custom' ], true ) ? $value : 'none';
			},
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_route_post_type', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_key',
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_route_taxonomy', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_key',
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_route_term_ids', [
			'type'              => 'array',
			'single'            => true,
			'default'           => [],
			'show_in_rest'      => [
				'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			],
			'sanitize_callback' => static function ( $value ): array {
				if ( ! \is_array( $value ) ) {
					return [];
				}
				return \array_values( \array_unique( \array_filter( \array_map( 'absint', $value ) ) ) );
			},
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_route_custom_id', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_key',
			'auth_callback'     => $authCallback,
		] );

		// A list of `{key, value, compare}` clauses (max 10) pushed
		// straight into WP_Query's `meta_query`. Exposed here so
		// publishers can filter archives against ACF / Meta Box / Pods
		// values without a bespoke screen-route-registry entry.
		register_post_meta( self::POST_TYPE, '_mua_route_meta_query', [
			'type'              => 'array',
			'single'            => true,
			'default'           => [],
			'show_in_rest'      => [
				'schema' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'key'     => [ 'type' => 'string' ],
							'value'   => [ 'type' => 'string' ],
							'compare' => [ 'type' => 'string' ],
						],
					],
				],
			],
			'sanitize_callback' => [ self::class, 'sanitiseMetaQuery' ],
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_route_orderby', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'date',
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $value ): string {
				$value   = \is_string( $value ) ? $value : 'date';
				$allowed = [ 'date', 'title', 'menu_order', 'modified', 'comment_count', 'rand' ];
				return \in_array( $value, $allowed, true ) ? $value : 'date';
			},
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_route_order', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'DESC',
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $value ): string {
				$value = \is_string( $value ) ? \strtoupper( $value ) : 'DESC';
				return $value === 'ASC' ? 'ASC' : 'DESC';
			},
			'auth_callback'     => $authCallback,
		] );

		// Which fallback slot this screen claims when the cascade runs.
		// `none` means never use as a fallback. `any` is the universal
		// 404. The typed values let publishers author a generic post
		// detail or category archive that catches every unmatched
		// item of that kind without naming every possible path.
		register_post_meta( self::POST_TYPE, '_mua_is_fallback_for', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'none',
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $value ): string {
				$allowed = [ 'none', 'post', 'page', 'archive_category', 'archive_tag', 'archive_taxonomy', 'author', 'search', 'any' ];
				$value   = \is_string( $value ) ? $value : 'none';
				return \in_array( $value, $allowed, true ) ? $value : 'none';
			},
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_show_in_nav', [
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => true,
			'sanitize_callback' => static fn( $v ): bool => (bool) $v,
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_show_in_side_nav', [
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => true,
			'sanitize_callback' => static fn( $v ): bool => (bool) $v,
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_side_nav_order', [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'sanitize_callback' => 'intval',
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_is_home', [
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => true,
			'sanitize_callback' => static fn( $v ): bool => (bool) $v,
			'auth_callback'     => $authCallback,
		] );

		register_post_meta( self::POST_TYPE, '_mua_nav_order', [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'sanitize_callback' => 'intval',
			'auth_callback'     => $authCallback,
		] );

		add_action( 'save_post_' . self::POST_TYPE, [ self::class, 'normaliseSlugWithinApp' ], 20, 1 );

		register_post_meta( self::POST_TYPE, '_mua_deeplink_path', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $value ): string {
				$value = \is_string( $value ) ? \trim( $value ) : '';
				if ( $value === '' ) {
					return '';
				}
				// Reject anything that fails the resolver's template guard so
				// a hostile template can never reach the runtime resolver
				// even if the read-time check were ever skipped.
				return \MustUse\Pub\Content\DeeplinkResolver::isSafeTemplate( $value ) ? $value : '';
			},
			'auth_callback'     => $authCallback,
		] );
	}

	/**
	 * Create a new screen post bound to an app.
	 *
	 * `_mua_app_id` is written atomically via `meta_input` so any `save_post`
	 * observers (orphan guard, cache invalidator) see the app linkage on the
	 * first fire
	 */
	public static function create( int $appId, string $title = '', int $parentId = 0 ): ?self {
		if ( $appId <= 0 ) {
			return NULL;
		}

		// Reject a parent that doesn't belong to this app — keeps the tree
		// strictly app-scoped and prevents a malformed REST payload from
		// attaching screens across app boundaries.
		if ( $parentId > 0 ) {
			$parent = self::find( $parentId );
			if ( ! $parent instanceof self || $parent->appId() !== $appId ) {
				$parentId = 0;
			}
		}

		$postId = wp_insert_post( [
			'post_type'   => self::POST_TYPE,
			'post_title'  => $title !== '' ? $title : __( 'Untitled Screen', 'mustuse-apps-pub' ),
			'post_status' => 'draft',
			'post_parent' => $parentId,
			'meta_input'  => [
				'_mua_app_id' => $appId,
			],
		], TRUE );

		// `$wp_error = true` was passed → wp_insert_post returns int|WP_Error,
		// never 0. The `=== 0` branch was dead.
		if ( is_wp_error( $postId ) ) {
			return NULL;
		}

		return self::find( $postId );
	}

	public static function find( int $id ): ?self {
		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || $post->post_type !== self::POST_TYPE ) {
			return NULL;
		}

		return new self( $post );
	}

	/**
	 * Returns all screens belonging to a given app.
	 *
	 * @return self[]
	 */
	public static function findByApp( int $appId, array $args = [] ): array {
		$defaults  = [
			'post_type'   => self::POST_TYPE,
			'meta_key'    => '_mua_app_id',
			'meta_value'  => $appId,
			'numberposts' => 200,
			'orderby'     => [ 'menu_order' => 'ASC', 'date' => 'ASC' ],
		];
		$queryArgs = \array_merge( $defaults, $args );
		$posts     = get_posts( $queryArgs );
		$screens   = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$screens[] = new self( $post );
			}
		}
		return $screens;
	}

	public function id(): int {
		return $this->post->ID;
	}

	public function title(): string {
		return $this->post->post_title;
	}

	/**
	 * The canonical per-app screen slug — what the manifest emits as
	 * `screen_id`, what the shell matches URLs against, what the
	 * publisher authored. Stored in `_mua_screen_slug`; `post_name` is
	 * a globally-unique derivative (`{app-slug}-{screen-slug}`) we use
	 * purely to satisfy WP's hierarchical uniqueness constraint without
	 * polluting the manifest with `-N` suffixes.
	 *
	 * Falls back to stripping the suffix / app-prefix from `post_name`
	 * for legacy screens that haven't been re-saved since the meta
	 * existed.
	 */
	public function slug(): string {
		$stored = (string) get_post_meta( $this->post->ID, '_mua_screen_slug', true );
		if ( $stored !== '' ) {
			return $stored;
		}
		return self::cleanSlugFromPostName( (string) $this->post->post_name, $this->appId() );
	}

	/**
	 * Back-compat: derive the publisher-intended slug from a legacy
	 * `post_name` that may carry an `{app-slug}-` prefix and/or a
	 * WP-assigned `-N` tail.
	 */
	private static function cleanSlugFromPostName( string $postName, int $appId ): string {
		$name = $postName;
		if ( $appId > 0 ) {
			$appSlug = self::appSlug( $appId );
			if ( $appSlug !== '' && \str_starts_with( $name, $appSlug . '-' ) ) {
				$name = (string) \substr( $name, \strlen( $appSlug ) + 1 );
			}
		}
		return (string) \preg_replace( '/-\d+$/', '', $name );
	}

	/**
	 * Narrow `get_post_field()` — its WP type is a broad union that
	 * phpstan won't let us `(string)` cast safely. For `post_name`
	 * specifically the value is a string in every sane state; fall
	 * through to '' for the `int`/`array<int>` branches phpstan worries
	 * about.
	 */
	private static function appSlug( int $appId ): string {
		if ( ! \function_exists( 'get_post_field' ) ) {
			return '';
		}
		$value = get_post_field( 'post_name', $appId );
		return \is_string( $value ) ? $value : '';
	}

	/**
	 * Retrieve screen-scoped metadata. All keys are prefixed with `_mua_` in storage.
	 */
	public function meta( string $key, mixed $default = NULL ): mixed {
		$value = get_post_meta( $this->post->ID, '_mua_' . $key, true );

		return $value !== '' ? $value : $default;
	}

	public function appId(): int {
		return (int) get_post_meta( $this->post->ID, '_mua_app_id', true );
	}

	public function parentId(): int {
		// Tree position uses WP's native post_parent (page-attributes support
		// is declared on the post type, which exposes both this and menu_order).
		return (int) $this->post->post_parent;
	}

	public function icon(): string {
		return (string) get_post_meta( $this->post->ID, '_mua_screen_icon', true );
	}

	public function order(): int {
		return (int) $this->post->menu_order;
	}

	// --- Screen role + routing accessors ---

	public function role(): string {
		$role = (string) get_post_meta( $this->post->ID, '_mua_screen_role', true );
		return \in_array( $role, [ 'static', 'archive', 'detail' ], true ) ? $role : 'static';
	}

	public function routeType(): string {
		$type = (string) get_post_meta( $this->post->ID, '_mua_route_type', true );
		return \in_array( $type, [ 'none', 'standard', 'custom' ], true ) ? $type : 'none';
	}

	public function routePostType(): string {
		return (string) get_post_meta( $this->post->ID, '_mua_route_post_type', true );
	}

	public function routeTaxonomy(): string {
		return (string) get_post_meta( $this->post->ID, '_mua_route_taxonomy', true );
	}

	/** @return list<int> */
	public function routeTermIds(): array {
		$raw = get_post_meta( $this->post->ID, '_mua_route_term_ids', true );
		if ( ! \is_array( $raw ) ) {
			return [];
		}
		return \array_values( \array_unique( \array_filter( \array_map( 'intval', $raw ) ) ) );
	}

	public function routeCustomId(): string {
		return (string) get_post_meta( $this->post->ID, '_mua_route_custom_id', true );
	}

	/** @return list<array{key: string, value: string, compare: string}> */
	public function routeMetaQuery(): array {
		$raw = get_post_meta( $this->post->ID, '_mua_route_meta_query', true );
		if ( ! \is_array( $raw ) ) {
			return [];
		}
		// The stored value is canonicalised through `sanitiseMetaQuery()`
		// on write, so every clause is already `{key, value, compare}`
		// with string fields. Re-narrow here to satisfy phpstan's
		// return-shape check rather than trust the write path alone.
		$clauses = [];
		foreach ( $raw as $clause ) {
			if ( ! \is_array( $clause ) ) {
				continue;
			}
			$clauses[] = [
				'key'     => (string) ( $clause['key']     ?? '' ),
				'value'   => (string) ( $clause['value']   ?? '' ),
				'compare' => (string) ( $clause['compare'] ?? '=' ),
			];
		}
		return $clauses;
	}

	public function routeOrderby(): string {
		$value = (string) get_post_meta( $this->post->ID, '_mua_route_orderby', true );
		return $value !== '' ? $value : 'date';
	}

	public function routeOrder(): string {
		$value = (string) get_post_meta( $this->post->ID, '_mua_route_order', true );
		return $value === 'ASC' ? 'ASC' : 'DESC';
	}

	/**
	 * Which fallback slot this screen claims, per the template cascade.
	 * `none` when the screen never acts as a fallback.
	 */
	public function fallbackFor(): string {
		$value = (string) get_post_meta( $this->post->ID, '_mua_is_fallback_for', true );
		return $value !== '' ? $value : 'none';
	}

	/**
	 * Find the first screen in $appId whose `_mua_is_fallback_for` claim
	 * matches any of $slots. `$slots` is ordered most-specific first;
	 * the first screen claiming any slot in that order wins.
	 *
	 * @param list<string> $slots
	 */
	public static function findFallback( int $appId, array $slots ): ?self {
		foreach ( $slots as $slot ) {
			$matches = get_posts( [
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 1,
				'meta_query'  => [
					'relation' => 'AND',
					[ 'key' => '_mua_app_id',          'value' => $appId, 'compare' => '=' ],
					[ 'key' => '_mua_is_fallback_for', 'value' => $slot,  'compare' => '=' ],
				],
			] );
			if ( $matches !== [] && $matches[0] instanceof WP_Post ) {
				return new self( $matches[0] );
			}
		}
		return null;
	}

	/**
	 * Sanitise a `meta_query` clause list from REST/admin input. Caps
	 * at 10 clauses to bound WP_Query cost; each clause keeps only
	 * `key`, `value`, `compare` with a hard-coded allowlist of
	 * comparison operators.
	 *
	 * @return list<array{key: string, value: string, compare: string}>
	 */
	public static function sanitiseMetaQuery( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$allowedCompare = [ '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' ];
		$clauses = [];
		foreach ( $value as $clause ) {
			if ( ! \is_array( $clause ) ) {
				continue;
			}
			$key = \is_string( $clause['key'] ?? null ) ? sanitize_key( $clause['key'] ) : '';
			if ( $key === '' ) {
				continue;
			}
			$compare = \is_string( $clause['compare'] ?? null ) ? \strtoupper( $clause['compare'] ) : '=';
			if ( ! \in_array( $compare, $allowedCompare, true ) ) {
				$compare = '=';
			}
			$raw   = $clause['value'] ?? '';
			$value = \is_string( $raw ) ? sanitize_text_field( $raw ) : (string) $raw;
			$clauses[] = [ 'key' => $key, 'value' => $value, 'compare' => $compare ];
			if ( \count( $clauses ) >= 10 ) {
				break;
			}
		}
		return $clauses;
	}

	public function showInNav(): bool {
		return (bool) get_post_meta( $this->post->ID, '_mua_show_in_nav', true );
	}

	public function navOrder(): int {
		return (int) get_post_meta( $this->post->ID, '_mua_nav_order', true );
	}

	public function showInSideNav(): bool {
		return (bool) get_post_meta( $this->post->ID, '_mua_show_in_side_nav', true );
	}

	public function sideNavOrder(): int {
		return (int) get_post_meta( $this->post->ID, '_mua_side_nav_order', true );
	}

	/**
	 * Enforce the per-app slug contract on every save:
	 *
	 *   `_mua_screen_slug` meta → the publisher-intended slug ("home").
	 *   `post_name` → `{app-slug}-{screen-slug}` ("kitchen-sink-demo-home").
	 *
	 * WP's `wp_unique_post_slug()` runs against every hierarchical post
	 * globally, so a naked "home" post_name collides with any other
	 * "home" page on the site and gets `-N` appended. Rather than fight
	 * that, we namespace the post_name with the app slug — guaranteed
	 * globally unique because app slugs are unique — while the manifest
	 * + shell read the clean intended slug from meta. Publishers get to
	 * keep "home" as their screen id on-device.
	 *
	 * Also migrates any pre-existing `post_name`/`_mua_locked_slug` that
	 * carry WP's old `-N` suffix into the new clean form.
	 *
	 * Direct `$wpdb` writes bypass `wp_update_post() → wp_unique_post_slug()`
	 * because that chain re-suffixes regardless of our save_post
	 * hook removal.
	 */
	public static function normaliseSlugWithinApp( int $postId ): void {
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}
		$post = get_post( $postId );
		if ( ! $post || $post->post_type !== self::POST_TYPE || $post->post_status === 'auto-draft' ) {
			return;
		}

		$current = (string) $post->post_name;
		if ( $current === '' ) {
			return;
		}

		$appId = (int) get_post_meta( $postId, '_mua_app_id', true );
		if ( $appId <= 0 ) {
			return;
		}

		$appSlug = self::appSlug( $appId );
		if ( $appSlug === '' ) {
			return;
		}

		// Lock read: the historical `_mua_locked_slug` stored the
		// then-current `post_name` (sometimes with `-N`). Re-interpret
		// it as the clean intended slug and migrate in-place.
		$lockedSlugRaw = (string) get_post_meta( $postId, '_mua_locked_slug', true );
		$lockedSlug    = $lockedSlugRaw !== '' ? self::cleanSlugFromPostName( $lockedSlugRaw, $appId ) : '';
		if ( $lockedSlugRaw !== '' && $lockedSlug !== $lockedSlugRaw ) {
			update_post_meta( $postId, '_mua_locked_slug', $lockedSlug );
		}

		// Canonical intended slug, from strongest source first:
		// 1. Explicit meta (already migrated / set by REST).
		// 2. Locked slug for shipped apps.
		// 3. Stripped `post_name` (covers fresh inserts + legacy state).
		$storedClean = (string) get_post_meta( $postId, '_mua_screen_slug', true );
		$intended    = $storedClean !== ''
			? $storedClean
			: ( $lockedSlug !== ''
				? $lockedSlug
				: self::cleanSlugFromPostName( $current, $appId ) );

		if ( $intended === '' ) {
			return;
		}

		// Revert-to-lock fires only when the lock names a genuinely
		// different clean slug (rename attempt on a shipped app).
		if ( $lockedSlug !== '' && $lockedSlug !== $intended ) {
			$intended = $lockedSlug;
			set_transient( 'mua_slug_lock_notice_' . get_current_user_id(), [
				'app_id' => $appId,
				'kept'   => $lockedSlug,
				'scope'  => 'screen',
			], 60 );
		}

		// Per-app uniqueness: if another screen in this app already
		// owns the intended slug, fall back to the suffixed form.
		if ( self::intendedSlugInUse( $intended, $appId, $postId ) ) {
			$intended = self::firstFreeSuffix( $intended, $appId, $postId );
		}

		$desiredPostName = $appSlug . '-' . $intended;

		if ( $storedClean !== $intended ) {
			update_post_meta( $postId, '_mua_screen_slug', $intended );
		}
		if ( $current !== $desiredPostName ) {
			self::writePostName( $postId, $desiredPostName );
		}
		if ( $lockedSlug === '' && self::appHasShipped( $appId ) ) {
			update_post_meta( $postId, '_mua_locked_slug', $intended );
		}
	}

	/**
	 * Rewrite `post_name` directly on the posts table, bypassing
	 * `wp_unique_post_slug()`. Callers MUST have computed the
	 * globally-unique form first — this is a "we know what we're doing"
	 * escape hatch, not a general slug mutator.
	 */
	private static function writePostName( int $postId, string $slug ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], [ 'ID' => $postId ] );
		clean_post_cache( $postId );
	}

	/**
	 * Is another screen in the same app already claiming this intended
	 * slug (via `_mua_screen_slug` meta)? Only checks screens other
	 * than `$excludeId`.
	 */
	private static function intendedSlugInUse( string $slug, int $appId, int $excludeId ): bool {
		$siblings = get_posts( [
			'post_type'   => self::POST_TYPE,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'exclude'     => [ $excludeId ],
			'meta_query'  => [
				'relation' => 'AND',
				[ 'key' => '_mua_app_id',      'value' => $appId, 'compare' => '=' ],
				[ 'key' => '_mua_screen_slug', 'value' => $slug,  'compare' => '=' ],
			],
		] );
		return ! empty( $siblings );
	}

	/**
	 * When the intended slug collides with a same-app sibling, find the
	 * first free `{slug}-N` variant. Caller retries with that value.
	 */
	private static function firstFreeSuffix( string $base, int $appId, int $excludeId ): string {
		for ( $n = 2; $n < 100; $n++ ) {
			$candidate = $base . '-' . $n;
			if ( ! self::intendedSlugInUse( $candidate, $appId, $excludeId ) ) {
				return $candidate;
			}
		}
		return $base . '-' . \random_int( 100, 9999 );
	}

	private static function appHasShipped( int $appId ): bool {
		$built = (string) get_post_meta( $appId, '_mua_last_build_at', true );
		if ( $built !== '' ) {
			return true;
		}
		return (int) get_post_meta( $appId, '_mua_app_version_code', true ) > 1;
	}

	public function isHome(): bool {
		return (bool) get_post_meta( $this->post->ID, '_mua_is_home', true );
	}

	public function deeplinkPath(): string {
		return (string) get_post_meta( $this->post->ID, '_mua_deeplink_path', true );
	}

	/**
	 * Resolve this screen's route into WP_Query-style args, or null if the
	 * route type is `none` (static screen) or `custom` with a missing
	 * registry entry.
	 *
	 * @return array<string, mixed>|null
	 */
	public function queryArgs( App $app ): ?array {
		$type = $this->routeType();

		if ( $type === 'standard' ) {
			$postType = $this->routePostType();
			if ( $postType === '' ) {
				return NULL;
			}
			$args = [
				'post_type' => $postType,
				'orderby'   => $this->routeOrderby(),
				'order'     => $this->routeOrder(),
			];

			$taxonomy = $this->routeTaxonomy();
			$termIds  = $this->routeTermIds();
			if ( $taxonomy !== '' && $termIds !== [] ) {
				$args['tax_query'] = [
					[
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $termIds,
						'operator' => 'IN',
					],
				];
			}

			$metaQuery = $this->routeMetaQuery();
			if ( $metaQuery !== [] ) {
				$args['meta_query'] = $metaQuery;
			}

			/** @see mua_screen_query_args filter — extensions shape final args. */
			return (array) apply_filters( 'mua_screen_query_args', $args, $this, $app );
		}

		if ( $type === 'custom' ) {
			$id = $this->routeCustomId();
			if ( $id === '' ) {
				return NULL;
			}
			$args = \MustUse\Pub\Routing\ScreenRouteRegistry::resolve( $id, $app );
			if ( ! \is_array( $args ) ) {
				return NULL;
			}
			return (array) apply_filters( 'mua_screen_query_args', $args, $this, $app );
		}

		return NULL;
	}

	public function status(): string {
		return $this->post->post_status;
	}

	public function modifiedDate(): string {
		return $this->post->post_modified;
	}

	/**
	 * Parse the screen's block editor content into a structured block tree.
	 */
	public function blockTree(): array {
		// Use VIP Block Data API if available, else fallback to parse_blocks
		if ( \class_exists( '\\Automattic\\VIP\\BlockDataApi\\API' ) ) {
			$blocks = \Automattic\VIP\BlockDataApi\API::get_blocks( $this->post->ID );
		} else {
			$blocks = parse_blocks( $this->post->post_content );
		}

		return \array_values( \array_filter(
			\array_map( [ $this, 'serializeBlock' ], $blocks ),
			static fn( ?array $b ) => $b !== NULL
		) );
	}

	private function serializeBlock( array $block ): ?array {
		// Skip empty/filler blocks.
		if ( empty( $block['blockName'] ) ) {
			return NULL;
		}

		$blockName = (string) $block['blockName'];
		$attrs     = $block['attrs'] ?? [];

		/** @see mua_block_attributes filter */
		$attrs = apply_filters( 'mua_block_attributes', $attrs, $blockName, $this->post->ID );

		$node = [
			'type'       => $blockName,
			'attributes' => \is_array( $attrs ) ? $attrs : [],
		];

		if ( ! empty( $block['innerBlocks'] ) ) {
			$node['children'] = \array_values( \array_filter(
				\array_map( [ $this, 'serializeBlock' ], $block['innerBlocks'] ),
				static fn( ?array $b ) => $b !== NULL
			) );
		}

		return $node;
	}
}
