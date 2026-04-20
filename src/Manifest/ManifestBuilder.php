<?php

declare(strict_types=1);

namespace MustUse\Pub\Manifest;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Extensions\ExtensionRegistry;

/**
 * Compiles an App's configuration into a JSON-serializable manifest.
 *
 * The manifest is the runtime contract between a pub and its shells.
 * Publisher-level defaults are merged with app-level overrides for
 * branding. Screens are serialized as block trees from mua_app_screen
 * posts. Renderer descriptions are collected from the filter registry.
 * The result is filterable via `mua_app_manifest`.
 */
final class ManifestBuilder {
	public const SCHEMA_VERSION           = 2;
	public const MIN_SHELL_SCHEMA_VERSION = 2;

	public function build( App $app ): array {
		$screens  = $this->buildScreens( $app );
		$appTypes = $app->appTypes();

		$manifest = [
			'version'              => self::SCHEMA_VERSION,
			'min_shell_version'    => self::MIN_SHELL_SCHEMA_VERSION,
			'app'                  => [
				'id'        => $app->id(),
				'slug'      => $app->slug(),
				'name'      => $app->title(),
				// `app_type` stays singular (first of app_types) for the
				// per-shell contract and back-compat with older shells; the
				// authoritative multi-platform list lives in `app_types`.
				'app_type'  => $appTypes[0] ?? 'mobile_ios',
				'app_types' => $appTypes,
			],
			'assets'               => $this->buildAssets( $app ),
			'branding'             => $this->buildBranding( $app ),
			'routing'              => $this->buildRouting( $app ),
			'navigation'           => $this->buildNavigation( $app ),
			'screen_tree'          => $this->buildScreenTree( $app ),
			'screens'              => $screens,
			'deeplink'             => $this->buildDeeplink( $app ),
			'fallback_map'         => $this->buildFallbackMap( $app ),
			'fallback_policy'      => $this->buildFallbackPolicy( $app ),
			'endpoints'            => $this->buildEndpoints( $app ),
			'requiredCapabilities' => $this->collectRequiredCapabilities( $screens ),
			'renderers'            => $this->collectRenderers(),
			'extensions'           => $this->buildExtensions( $app ),
			'cache_policy'         => $this->buildCachePolicy( $app ),
			'cache_invalidations'  => $this->buildCacheInvalidations( $app ),
			'subscriber_state'     => $this->buildSubscriberState( $app ),
		];

		// Let active extensions inject into manifest via filters.
		$activeExtensions = ExtensionRegistry::getActiveForApp( $app );
		if ( ! empty( $activeExtensions ) ) {
			/** @see mua_allowed_blocks filter */
			apply_filters( 'mua_allowed_blocks', [], $app, $activeExtensions );
		}

		/** @see mua_app_manifest filter */
		return apply_filters( 'mua_app_manifest', $manifest, $app );
	}

	private function buildBranding( App $app ): array {
		$defaults  = get_option( 'mua_publisher_branding', [] );
		$overrides = $app->meta( 'branding', [] );

		// App-level overrides take precedence over publisher defaults
		return \array_merge(
			\is_array( $defaults ) ? $defaults : [],
			\is_array( $overrides ) ? $overrides : []
		);
	}

	private function buildRouting( App $app ): array {
		return \MustUse\Pub\Routing\ScreenRouteProjector::projectFor( $app )['routing'];
	}

	/**
	 * Emit the navigation block in `{bottom_nav, drawer}` shape so every
	 * shell consumer reads the same keys. `bottom_nav` is the flat list
	 * of show-in-nav screens (capped at 5 by the validator per iOS HIG).
	 * `drawer` is the full screen_tree — shells render it as a side menu
	 * when there are more screens than the tab bar can hold.
	 */
	private function buildNavigation( App $app ): array {
		$projection = \MustUse\Pub\Routing\ScreenRouteProjector::projectFor( $app );

		$navigation = [
			'bottom_nav' => $projection['navigation'],
			// `side_nav` is an explicit, publisher-curated flat list —
			// distinct from the auto-generated `drawer` (which is the
			// full hierarchical tree). Shells pick one or render both
			// depending on form factor.
			'side_nav'   => $projection['side_nav'] ?? [],
			'drawer'     => $projection['screen_tree'],
		];

		/** @see mua_app_navigation filter */
		return apply_filters( 'mua_app_navigation', $navigation, $app );
	}

	private function buildScreenTree( App $app ): array {
		$tree = \MustUse\Pub\Routing\ScreenRouteProjector::projectFor( $app )['screen_tree'];

		/** @see mua_app_screen_tree filter — extension hook for tree-shape adjustments. */
		return apply_filters( 'mua_app_screen_tree', $tree, $app );
	}

	/**
	 * Serialize mua_app_screen posts into structured block trees.
	 */
	private function buildScreens( App $app ): array {
		$screens = Screen::findByApp( $app->id(), [
			'post_status' => 'publish',
			'orderby'     => [ 'menu_order' => 'ASC', 'date' => 'ASC' ],
		] );

		$pathBySlug = [];
		$homeSlug   = '';
		foreach ( \MustUse\Pub\Routing\ScreenRouteProjector::projectFor( $app )['screens'] as $row ) {
			$slug                = (string) ( $row['screen_id'] ?? '' );
			$pathBySlug[ $slug ] = (string) ( $row['path'] ?? '/' . $slug );
			if ( ! empty( $row['is_home'] ) ) {
				$homeSlug = $slug;
			}
		}

		$advertised         = \MustUse\Pub\Manifest\CapabilityRegistry::getAdvertised( $app );
		$nativeCapabilities = $advertised['native_capabilities'] ?? [];

		return \array_map( function ( Screen $screen ) use ( $app, $nativeCapabilities, $pathBySlug, $homeSlug ) {
			$blockTree = $screen->blockTree();

			$blockTree = \array_filter( $blockTree, function ( $block ) {
				if ( ! isset( $block['type'] ) )
					return true;
				if ( \strpos( $block['type'], 'mustuse-apps-pub-campaign/' ) === 0 && $block['type'] !== 'mustuse-apps-pub-campaign/rich-content' ) {
					return false;
				}
				return true;
			} );

			$blockTree = $this->filterBlocksByCapabilities( $blockTree, $nativeCapabilities );

			/** @see mua_screen_block_tree filter */
			$blockTree = apply_filters( 'mua_screen_block_tree', $blockTree, $screen->id(), $app );

			$slug  = $screen->slug();
			$entry = [
				'id'         => $slug,
				'path'       => $pathBySlug[ $slug ] ?? '/' . $slug,
				'type'       => 'screen',
				'title'      => $screen->title(),
				'block_tree' => $blockTree,
			];

			if ( $slug === $homeSlug ) {
				$entry['is_home'] = true;
			}

			$icon = $screen->icon();
			if ( $icon !== '' ) {
				$entry['icon'] = $icon;
			}

			return $entry;
		}, $screens );
	}

	/**
	 * Recursively filter blocks by advertised capabilities.
	 * Blocks with a 'capability' attribute not present in nativeCapabilities are removed.
	 */
	private function filterBlocksByCapabilities( array $blocks, array $nativeCapabilities ): array {
		$filtered = [];
		foreach ( $blocks as $block ) {
			$capability = $block['attributes']['capability'] ?? NULL;
			if ( $capability && empty( $nativeCapabilities[ $capability ] ) ) {
				continue; // Skip blocks requiring unavailable capability
			}

			// Recursively filter children/innerBlocks
			if ( isset( $block['children'] ) && \is_array( $block['children'] ) ) {
				$block['children'] = $this->filterBlocksByCapabilities( $block['children'], $nativeCapabilities );
			}
			if ( isset( $block['innerBlocks'] ) && \is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->filterBlocksByCapabilities( $block['innerBlocks'], $nativeCapabilities );
			}
			$filtered[] = $block;
		}
		return $filtered;
	}

	/**
	 * Build deep link configuration from app meta.
	 */
	private function buildDeeplink( App $app ): ?array {
		$scheme = $app->meta( 'deeplink_scheme', '' );
		$host   = $app->meta( 'deeplink_host', '' );

		if ( empty( $scheme ) && empty( $host ) ) {
			return NULL;
		}

		$deeplink = [];
		if ( ! empty( $scheme ) ) {
			$deeplink['scheme'] = $scheme;
		}
		if ( ! empty( $host ) ) {
			$deeplink['host'] = $host;
		}

		return $deeplink;
	}

	/**
	 * Emit a map of fallback slot → screen_id so the shell can short-
	 * circuit to the publisher-authored fallback without a REST round-
	 * trip when a deep link lands on missing content. Slots are filled
	 * from each published screen's `_mua_is_fallback_for` meta.
	 *
	 * Keys match `DeeplinkResolver::fallbackSlotsFor()` + `any`.
	 *
	 * @return array<string, string>
	 */
	private function buildFallbackMap( App $app ): array {
		$slots = [ 'post', 'page', 'archive_category', 'archive_tag', 'archive_taxonomy', 'author', 'search', 'any' ];
		$map   = [];
		foreach ( $slots as $slot ) {
			$screen = Screen::findFallback( $app->id(), [ $slot ] );
			if ( $screen instanceof Screen ) {
				$map[ $slot ] = $screen->slug();
			}
		}
		/** @see mua_app_fallback_map filter */
		return (array) apply_filters( 'mua_app_fallback_map', $map, $app );
	}

	/**
	 * Per-app policy for when the cascade bottoms out without a match:
	 *   web         → shell opens the WP URL in an in-app browser
	 *   screen_404  → shell renders the authored 404 screen (`any` slot)
	 *   home        → shell routes to `/`
	 */
	private function buildFallbackPolicy( App $app ): array {
		$mode = (string) $app->meta( 'fallback_policy', 'web' );
		if ( ! \in_array( $mode, [ 'web', 'screen_404', 'home' ], true ) ) {
			$mode = 'web';
		}
		$webBase = '';
		if ( $mode === 'web' ) {
			$raw    = \function_exists( 'get_option' ) ? get_option( 'home', '' ) : '';
			$option = \is_string( $raw ) ? $raw : '';
			$webBase = $option !== ''
				? $option
				: ( \function_exists( 'site_url' ) ? (string) site_url() : '' );
		}
		$policy = [
			'mode'         => $mode,
			'webview_base' => $webBase,
		];
		/** @see mua_app_fallback_policy filter */
		return (array) apply_filters( 'mua_app_fallback_policy', $policy, $app );
	}

	private function buildEndpoints( App $app ): array {
		$base = rest_url( 'mustuse-apps-pub/v1/apps/' . $app->slug() );

		$endpoints = [
			'content'          => $base . '/content',
			'terms'            => $base . '/terms',
			'capabilities'     => $base . '/capabilities',
			'resolve_deeplink' => $base . '/resolve-deeplink',
			'auth_complete'    => $base . '/auth/complete',
			'auth_status'      => $base . '/auth/status',
			'auth_logout'      => $base . '/auth/logout',
			'subscriber_state' => $base . '/subscriber/state',
			'bookmarks'        => $base . '/bookmarks',
			'history'          => $base . '/history',
			'push_enroll'      => $base . '/push/enroll',
			'menu'             => $base . '/menus/{location}',
			'comments'         => $base . '/comments/{post_id}',
		];

		/** @see mua_app_endpoints filter */
		return apply_filters( 'mua_app_endpoints', $endpoints, $app );
	}

	/**
	 * Walk all screen block trees and collect unique capability requirements.
	 *
	 * Capabilities are declared per block instance via the `capability`
	 * attribute, not in block.json. The manifest's top-level array is
	 * derived from the actual block tree.
	 */
	private function collectRequiredCapabilities( array $screens ): array {
		$capabilities = [];

		foreach ( $screens as $screen ) {
			$blockTree = $screen['block_tree'] ?? [];
			$this->extractCapabilitiesFromBlocks( $blockTree, $capabilities );
		}

		return \array_values( \array_unique( $capabilities ) );
	}

	private function extractCapabilitiesFromBlocks( array $blocks, array &$capabilities ): void {
		foreach ( $blocks as $block ) {
			$capability = $block['attributes']['capability'] ?? NULL;
			if ( $capability !== NULL && CapabilityRegistry::isBlockClassified( $capability ) ) {
				$capabilities[] = $capability;
			}

			// auth-gate has implicit biometrics requirement
			$type = $block['type'] ?? ( $block['blockName'] ?? '' );
			if ( $type === 'mustuse-apps-pub/auth-gate' ) {
				$capabilities[] = 'biometrics';
			}

			$children = $block['children'] ?? ( $block['innerBlocks'] ?? [] );
			if ( ! empty( $children ) ) {
				$this->extractCapabilitiesFromBlocks( $children, $capabilities );
			}
		}
	}

	/**
	 * Collect renderer descriptions from the filter registry.
	 *
	 * Built-in blocks register their renderer.json files here too —
	 * no privileged internal path 
	 */
	private function collectRenderers(): array {
		/** @see mua_renderer_descriptions filter */
		return apply_filters( 'mua_renderer_descriptions', [] );
	}

	/**
	 * Build the extensions array for the manifest.
	 *
	 * Only includes extensions that are both registered and active for
	 * this specific app.
	 */
	private function buildExtensions( App $app ): array {
		$active = ExtensionRegistry::getActiveForApp( $app );

		if ( empty( $active ) ) {
			return [];
		}

		return \array_values( \array_map( static fn( array $ext ) => [
			'plugin_slug'    => $ext['plugin_slug'],
			'plugin_name'    => $ext['plugin_name'],
			'plugin_version' => $ext['plugin_version'],
		], $active ) );
	}

	/**
	 * Build the `assets` manifest object (contracts/pub-shell/manifest-schema.md).
	 *
	 * Icon, logo, and splash URLs are stored inside the single
	 * `_mua_branding` meta array that EditorialController's Save-App
	 * flow writes — not as standalone meta keys. Reading them as
	 * standalone keys (the previous behaviour) silently produced an
	 * empty `assets` object because nothing wrote those keys.
	 */
	private function buildAssets( App $app ): array {
		$branding = $app->meta( 'branding', [] );
		$branding = \is_array( $branding ) ? $branding : [];

		$assets = \array_filter( [
			'icon'   => (string) ( $branding['icon_url'] ?? '' ),
			'splash' => (string) ( $branding['splash_url'] ?? '' ),
			'logo'   => (string) ( $branding['logo_url'] ?? '' ),
		], static fn( string $v ): bool => $v !== '' );

		/** @see mua_app_assets filter — asset overrides from extensions. */
		return (array) apply_filters( 'mua_app_assets', $assets, $app );
	}

	/**
	 * Build the `cache_policy` manifest object
	 *
	 * Returns the three-tier default expected by the shell cache controller,
	 * then lets extensions override via the `mua_app_cache_policy` filter.
	 */
	private function buildCachePolicy( App $app ): array {
		$stored = $app->meta( 'cache_policy', [] );
		$policy = \is_array( $stored ) ? $stored : [];

		$defaults = [
			'manifest' => [ 'ttl_seconds' => 300, 'strategy' => 'stale-while-revalidate' ],
			'content'  => [ 'ttl_seconds' => 900, 'strategy' => 'stale-while-revalidate' ],
			'assets'   => [ 'ttl_seconds' => 86400, 'strategy' => 'cache-first' ],
		];

		$policy = \array_merge( $defaults, $policy );

		/** @see mua_app_cache_policy filter  */
		return (array) apply_filters( 'mua_app_cache_policy', $policy, $app );
	}

	/**
	 * Build the `cache_invalidations` manifest object.
	 *
	 * Emits a monotonic revision token per cached surface so the shell
	 * can detect publisher-driven invalidations without polling content.
	 */
	private function buildCacheInvalidations( App $app ): array {
		$token = static function ( string $key ) use ( $app ): string {
			$value = (string) $app->meta( 'cache_rev_' . $key, '' );
			return $value !== '' ? $value : '0';
		};

		$invalidations = [
			'manifest' => $token( 'manifest' ),
			'content'  => $token( 'content' ),
			'assets'   => $token( 'assets' ),
		];

		return (array) apply_filters( 'mua_app_cache_invalidations', $invalidations, $app );
	}

	/**
	 * Build the `subscriber_state` manifest object.
	 *
	 * The pub plugin itself does not know tiers/entitlements — those
	 * come from the auth plugin (Leaky Paywall, MemberPress, etc.)
	 * via the `mua_app_subscriber_state` contribution filter.
	 *
	 * Returns null for non-paywalled apps so shells can skip the
	 * subscriber pipeline entirely.
	 */
	private function buildSubscriberState( App $app ): ?array {
		/** @see mua_app_subscriber_state filter */
		$contributed = apply_filters( 'mua_app_subscriber_state', NULL, $app );

		if ( $contributed === NULL && ! \function_exists( 'wp_get_current_user' ) ) {
			return NULL;
		}

		$base = [
			'logged_in'    => false,
			'roles'        => [],
			'capabilities' => [],
		];
		if ( \function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && (int) $user->ID > 0 ) {
				$base['logged_in']    = true;
				$base['user_id']      = (int) $user->ID;
				$base['display_name'] = (string) $user->display_name;
				$base['roles']        = \is_array( $user->roles ) ? \array_values( \array_filter( $user->roles, 'is_string' ) ) : [];
				$base['capabilities'] = \is_array( $user->allcaps ) ? \array_keys( \array_filter( $user->allcaps ) ) : [];
			}
		}

		if ( \is_array( $contributed ) ) {
			$base = \array_merge( $base, $contributed );
		}

		return $base;
	}
}
