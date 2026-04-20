<?php

declare(strict_types=1);

namespace MustUse\Pub;

use MustUse\Pub\Admin\EditorialController;
use MustUse\Pub\Api\Editorial\ShipRoute;
use MustUse\Pub\Api\Shell\AuthCompleteRoute;
use MustUse\Pub\Api\Shell\AuthLogoutRoute;
use MustUse\Pub\Api\Shell\AuthStatusRoute;
use MustUse\Pub\Api\Shell\CapabilityRoute;
use MustUse\Pub\Api\Shell\ContentRoute;
use MustUse\Pub\Api\Shell\DeeplinkRoute;
use MustUse\Pub\Api\Shell\ManifestRoute;
use MustUse\Pub\Api\Shell\SubscriberStateRoute;
use MustUse\Pub\Api\WellKnown\AndroidAssetLinks;
use MustUse\Pub\Api\WellKnown\AppleAppSiteAssociation;
use MustUse\Pub\Blocks\AllowedBlocks;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Events\PostLifecycle;
use MustUse\Pub\Jobs\InvalidateManifestCache;
use MustUse\Pub\Jobs\ProjectBuildToGithub;
use MustUse\Pub\Jobs\RebuildManifest;
use MustUse\Pub\Preview\PreviewRoute;

final class Plugin {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		self::registerPostTypes();
		self::registerBlocks();
		self::registerRestRoutes();
		self::registerAdminPages();
		self::registerJobs();
		self::registerEventListeners();
	}

	private static function registerPostTypes(): void {
		add_action( 'init', [ App::class, 'registerPostType' ] );
		add_action( 'init', [ Screen::class, 'registerPostType' ] );
		add_action( 'after_setup_theme', static function (): void {
			// Register a theme-nav-menu location so the content-showcase
			// seeder can attach a menu to it. Harmless for sites that
			// never run the seeder — `get_registered_nav_menus()` just
			// surfaces an empty slot in Appearance → Menus.
			if ( \function_exists( 'register_nav_menus' ) ) {
				register_nav_menus( [
					\MustUse\Pub\Admin\Seeders\ContentShowcaseSeeder::MENU_LOCATION
						=> __( 'MustUse — Content showcase', 'mustuse-apps-pub' ),
				] );
			}
		} );
	}

	private static function registerBlocks(): void {
		add_action( 'init', static function (): void {
			if ( \class_exists( \Blockstudio\Build::class) ) {
				\Blockstudio\Build::init( [
					'dir' => MUA_PUB_DIR . 'src/Blocks',
				] );
			}
		} );

		// Blockstudio compiles / minifies per-block CSS into _dist/ on demand.
		// Turn on minification so the mobile shell inlines the smallest
		// payload possible. SCSS processing auto-triggers on `.scss`
		// extensions so no extra toggle is needed there.
		add_filter( 'blockstudio/settings/assets/minify/css', '__return_true' );
		add_filter( 'blockstudio/settings/assets/minify/js', '__return_true' );

		AllowedBlocks::register();
		self::registerBuiltInRenderers();
		\MustUse\Pub\Manifest\BlockAttributeNormalizer::register();

		add_filter( 'block_categories_all', static function ( array $cats ): array {
			// Two categories matching the two block namespaces in use
			// Content blocks target `mustuse-apps-pub/` and
			// land in the App Content category; canvas blocks have their
			// own category.
			$cats[] = [
				'slug'  => 'mustuse-apps-pub',
				'title' => __( 'App Content', 'mustuse-apps-pub' ),
			];
			$cats[] = [
				'slug'  => 'mustuse-apps-pub-canvas',
				'title' => __( 'App Canvas', 'mustuse-apps-pub' ),
			];
			return $cats;
		} );
	}

	/**
	 * Register renderer descriptions for all built-in blocks.
	 *
	 * Built-in blocks use the same registration path as third-party
	 * extensions — no privileged internal path exists. The renderer map is
	 * memoised per request rather than written to a long-lived transient:
	 * the filesystem walk is cheap and a version-keyed transient would
	 * serve a stale map across point releases that don't bump the version.
	 */
	/**
	 * Collect each built-in block's `native.json` sidecar and contribute it
	 * to the manifest's renderer map.
	 *
	 * `native.json` is our block-companion convention — same role as
	 * `block.json` but declares how a block renders in a native shell
	 * (allowed_tags, callback contract, platform-specific behaviour).
	 * Extensions can declare their own `native.json` and have the manifest
	 * pick it up via the same filter.
	 */
	private static function registerBuiltInRenderers(): void {
		add_filter( 'mua_renderer_descriptions', static function ( array $renderers ): array {
			static $builtin = NULL;

			if ( $builtin === NULL ) {
				$builtin     = [];
				$nativeFiles = \array_merge(
					\glob( MUA_PUB_DIR . 'src/Blocks/*/native.json' ) ?: [],
					\glob( MUA_PUB_DIR . 'src/Blocks/*/*/native.json' ) ?: []
				);

				foreach ( $nativeFiles as $nativeFile ) {
					$blockJsonPath = \dirname( $nativeFile ) . '/block.json';

					if ( ! \is_file( $blockJsonPath ) ) {
						continue;
					}

					$blockJson = \json_decode( (string) \file_get_contents( $blockJsonPath ), TRUE );
					$blockName = $blockJson['name'] ?? NULL;

					if ( ! \is_string( $blockName ) || $blockName === '' ) {
						continue;
					}

					$native = \json_decode( (string) \file_get_contents( $nativeFile ), TRUE );
					if ( \is_array( $native ) ) {
						$builtin[ $blockName ] = $native;
					}
				}
			}

			return \array_merge( $renderers, $builtin );
		}, 5 ); // Priority 5: built-in blocks register before extensions (default 10)
	}

	private static function registerRestRoutes(): void {
		add_action( 'rest_api_init', static function (): void {
			// Shell API (AppKey-secured)
			( new ManifestRoute() )->register();
			( new ContentRoute() )->register();
			( new \MustUse\Pub\Api\Shell\TermsRoute() )->register();
			( new CapabilityRoute() )->register();
			( new DeeplinkRoute() )->register();

			( new \MustUse\Pub\Api\Shell\BookmarksRoute() )->register();
			( new \MustUse\Pub\Api\Shell\HistoryRoute() )->register();
			( new \MustUse\Pub\Api\Shell\PushEnrollRoute() )->register();
			( new \MustUse\Pub\Api\Shell\MenuRoute() )->register();
			( new \MustUse\Pub\Api\Shell\CommentsRoute() )->register();

			// Shell Auth & Monetization API
			( new AuthCompleteRoute() )->register();
			( new AuthStatusRoute() )->register();
			( new AuthLogoutRoute() )->register();
			( new SubscriberStateRoute() )->register();

			// Editorial API (capability-secured)
			( new ShipRoute() )->register();
			( new \MustUse\Pub\Api\Editorial\BuildStatusRoute() )->register();
			( new \MustUse\Pub\Api\Editorial\TestGithubRoute() )->register();
			( new \MustUse\Pub\Api\Editorial\DeploymentRequirementsRoute() )->register();
			( new \MustUse\Pub\Api\Editorial\ScreensTreeRoute() )->register();
		} );

		// WellKnown files are served at the DOMAIN ROOT, not the REST API
		// mount point, because Apple/Google fetch from fixed paths.
		// Registration happens outside rest_api_init so the init-hook
		// listeners land before `init` actually fires.
		( new AppleAppSiteAssociation() )->register();
		( new AndroidAssetLinks() )->register();
	}

	private static function registerAdminPages(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', static function (): void {
			( new EditorialController() )->registerMenuPages();
		} );

		// mua_app list columns (native edit.php?post_type=mua_app).
		add_filter( 'manage_' . App::POST_TYPE . '_posts_columns', [ EditorialController::class, 'filterAppColumns' ] );
		add_action( 'manage_' . App::POST_TYPE . '_posts_custom_column', [ EditorialController::class, 'renderAppColumn' ], 10, 2 );
		add_filter( 'manage_edit-' . App::POST_TYPE . '_sortable_columns', [ EditorialController::class, 'filterAppSortableColumns' ] );
		add_action( 'pre_get_posts', [ EditorialController::class, 'applyAppListOrderBy' ] );

		// mua_app edit screen — metaboxes + save handler.
		add_action( 'add_meta_boxes_' . App::POST_TYPE, [ EditorialController::class, 'registerAppMetaboxes' ] );
		add_action( 'save_post_' . App::POST_TYPE, [ EditorialController::class, 'saveAppMetaboxes' ], 10, 2 );

		// Enqueue the editorial bundle on native mua_app edit/list screens
		// plus the Publisher Settings page.
		add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
			global $typenow;
			$onAppScreen    = \in_array( $hook, [ 'post.php', 'post-new.php', 'edit.php' ], true ) && $typenow === App::POST_TYPE;
			$onSettingsPage = $hook === 'mustuse-apps_page_mustuse-apps-settings';
			if ( $onAppScreen || $onSettingsPage ) {
				( new EditorialController() )->enqueueAssets();
			}
		} );

		add_action( 'admin_init', [ EditorialController::class, 'handleFormSubmissions' ] );
	}

	private static function registerJobs(): void {
		InvalidateManifestCache::register();
		RebuildManifest::register();
		ProjectBuildToGithub::register();
	}

	private static function registerEventListeners(): void {
		PostLifecycle::listen();
		PreviewRoute::register();
		\MustUse\Pub\Admin\AppPermissions::register();
		\MustUse\Pub\Admin\KitchenSinkScaffold::register();
		\MustUse\Pub\Admin\ContentShowcaseScaffold::register();
	}
}
