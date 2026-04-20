<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Extensions\ExtensionRegistry;
use MustUse\Pub\Routing\ScreenRouteRegistry;
use MustUse\Pub\Support\AppKeyManager;
use MustUse\Pub\Support\SecureStorage;
use MustUse\Pub\Support\Vite;

/**
 * Editorial admin surface.
 *
 * Apps live on the native `mua_app` edit screen: block canvas for
 * composition, metaboxes for branding / distribution / developer /
 * push / extensions. The top-level `mustuse-apps` menu is a thin shell
 * whose sole job is to land publishers on the native post list; the
 * only custom page we still own is publisher-level Settings.
 */
final class EditorialController {
	public const APP_NONCE = '_mua_app_metabox_nonce';

	public function registerMenuPages(): void {
		// Top-level parent menu. `mua_app` declares `show_in_menu => 'mustuse-apps'`,
		// so WordPress auto-injects "All Apps" / "Add New" beneath this entry; the
		// callback is only hit if a user lands on `admin.php?page=mustuse-apps`
		// directly (e.g. legacy bookmark), in which case we forward to the list.
		add_menu_page(
			__( 'MustUse Apps', 'mustuse-apps-pub' ),
			__( 'MustUse Apps', 'mustuse-apps-pub' ),
			'edit_pages',
			'mustuse-apps',
			[ $this, 'renderAppsRedirect' ],
			'dashicons-smartphone',
			30
		);

		$settingsHook = add_submenu_page(
			'mustuse-apps',
			__( 'Publisher Settings', 'mustuse-apps-pub' ),
			__( 'Settings', 'mustuse-apps-pub' ),
			'manage_options',
			'mustuse-apps-settings',
			[ $this, 'renderSettingsPage' ]
		);

		add_action( "load-{$settingsHook}", [ $this, 'enqueueAssets' ] );

		add_action( 'admin_notices', [ self::class, 'renderEmptyAppsNotice' ] );

		// Screen metaboxes and orphan guards live on native post screens —
		// wire them once per registerMenuPages() call (fires on admin_menu).
		$this->registerPostScreenHooks();
	}

	/**
	 * Opinionated first-run CTA on the All Apps list. WP's "No items found"
	 * message gives publishers no path forward; this replaces it with a
	 * branded card pointing at Add New, the docs, and Settings.
	 */
	public static function renderEmptyAppsNotice(): void {
		global $pagenow, $typenow;
		if ( $pagenow !== 'edit.php' || $typenow !== App::POST_TYPE ) {
			return;
		}

		$counts = wp_count_posts( App::POST_TYPE );
		$total  = 0;
		foreach ( [ 'publish', 'draft', 'pending', 'private', 'future' ] as $status ) {
			$total += (int) ( $counts->{$status} ?? 0 );
		}
		if ( $total > 0 ) {
			return;
		}

		$addUrl      = admin_url( 'post-new.php?post_type=' . App::POST_TYPE );
		$settingsUrl = admin_url( 'admin.php?page=mustuse-apps-settings' );
		?>
		<div class="notice notice-info" style="padding:18px 22px;">
			<h2 style="margin:0 0 6px 0;">
				<?php esc_html_e( 'Welcome — let\'s ship your first app.', 'mustuse-apps-pub' ); ?>
			</h2>
			<p style="margin:0 0 12px 0; max-width:60ch;">
				<?php esc_html_e( 'An app is built from screens you author in the block editor. The Publisher signs a manifest and projects a Laravel + NativePHP shell to GitHub on every Ship It click — Bifrost takes it from there.', 'mustuse-apps-pub' ); ?>
			</p>
			<p style="margin:0;">
				<a href="<?php echo esc_url( $addUrl ); ?>" class="button button-primary button-hero">
					<?php esc_html_e( 'Create your first app', 'mustuse-apps-pub' ); ?>
				</a>
				<a href="<?php echo esc_url( $settingsUrl ); ?>" class="button" style="margin-left:8px;">
					<?php esc_html_e( 'Publisher settings', 'mustuse-apps-pub' ); ?>
				</a>
			</p>
		</div>
		<style>
			.wrap .wp-list-table,
			.wrap .subsubsub,
			.wrap .tablenav {
				display: none;
			}
		</style>
		<?php
	}

	public function renderAppsRedirect(): void {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . App::POST_TYPE ) );
		exit;
	}

	public function renderSettingsPage(): void {
		$branding = get_option( 'mua_publisher_branding', [] );

		if ( ! \is_array( $branding ) ) {
			$branding = [];
		}

		include MUA_PUB_DIR . 'views/editorial/settings.php';
	}

	/**
	 * Handles POST submissions from the Settings page. App-scoped
	 * submissions flow through `save_post_mua_app`.
	 */
	public static function handleFormSubmissions(): void {
		if ( ! isset( $_POST['mua_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) $_POST['mua_action'] );

		if ( $action !== 'save_settings' ) {
			return;
		}

		$nonce = isset( $_POST['_mua_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mua_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mua_' . $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mustuse-apps-pub' ) );
		}

		self::handleSaveSettings();
	}

	private static function handleSaveSettings(): void {
		$branding = [
			'name'               => sanitize_text_field( $_POST['publisher_name'] ?? '' ),
			'logo_url'           => esc_url_raw( $_POST['publisher_logo_url'] ?? '' ),
			'primary_color'      => sanitize_hex_color( $_POST['publisher_primary_color'] ?? '' ) ?: '',
			'accent_color'       => sanitize_hex_color( $_POST['publisher_accent_color'] ?? '' ) ?: '',
			'privacy_policy_url' => esc_url_raw( $_POST['publisher_privacy_url'] ?? '' ),
			'support_email'      => sanitize_email( $_POST['publisher_support_email'] ?? '' ),
		];

		update_option( 'mua_publisher_branding', $branding );

		wp_redirect( admin_url( 'admin.php?page=mustuse-apps-settings&saved=1' ) );
		exit;
	}

	public function enqueueAssets(): void {
		Vite::enqueue( 'editorial' );

		// Enqueue wp.media first so its core deps (underscore, media-editor,
		// media-views, media-models) are registered before we splice them
		// into the mua-editorial dep list.
		wp_enqueue_media();

		// Lock `window._` to the genuine Underscore *immediately* after
		// its script tag — production (mustuse.com) has another plugin
		// that later replaces `_` with a lodash build missing `isArray`
		// / `defaults`, which crashes `media-editor`'s DOM-ready init
		// before our bundle even runs. `configurable: true` keeps the
		// escape hatch open if we ever need to unlock it.
		$lockUnderscore = <<<'JS'
(function () {
    var u = window._;
    if (!u || typeof u.isArray !== 'function' || typeof u.defaults !== 'function') return;
    try {
        Object.defineProperty(window, '_', {
            get: function () { return u; },
            set: function () {},
            configurable: true,
        });
    } catch (e) {}
})();
JS;
		wp_add_inline_script( 'underscore', $lockUnderscore, 'after' );

		// `wp.apiFetch` powers Ship It and Test Connection; the media stack
		// powers the Branding metabox's image picker. Declaring them as
		// explicit deps forces WP to print them before our bundle, so a
		// third-party `window._` override loaded later can't race us.
		if ( wp_script_is( 'mua-editorial', 'registered' ) || wp_script_is( 'mua-editorial', 'enqueued' ) ) {
			global $wp_scripts;
			if ( $wp_scripts instanceof \WP_Scripts && isset( $wp_scripts->registered['mua-editorial'] ) ) {
				$required = [ 'wp-api-fetch', 'underscore', 'media-editor', 'media-views', 'media-models' ];
				foreach ( $required as $dep ) {
					if ( ! \in_array( $dep, $wp_scripts->registered['mua-editorial']->deps, true ) ) {
						$wp_scripts->registered['mua-editorial']->deps[] = $dep;
					}
				}
			}
			wp_localize_script( 'mua-editorial', 'MUA_EDITORIAL', [
				'restBase' => esc_url_raw( rest_url( 'mustuse-apps-pub/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			] );
		}
	}

	// ---------------------------------------------------------------------
	// mua_app list screen — sortable admin columns
	// ---------------------------------------------------------------------

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function filterAppColumns( array $columns ): array {
		$date = $columns['date'] ?? NULL;
		unset( $columns['date'] );

		$columns['mua_platforms']  = __( 'Platforms', 'mustuse-apps-pub' );
		$columns['mua_version']    = __( 'Version', 'mustuse-apps-pub' );
		$columns['mua_screens']    = __( 'Screens', 'mustuse-apps-pub' );
		$columns['mua_last_build'] = __( 'Last build', 'mustuse-apps-pub' );

		if ( $date !== NULL ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	public static function renderAppColumn( string $column, int $postId ): void {
		$app = App::find( $postId );
		if ( ! $app instanceof App ) {
			echo '—';
			return;
		}

		switch ( $column ) {
			case 'mua_platforms':
				$labels = [ 'mobile_ios' => 'iOS', 'mobile_android' => 'Android' ];
				$names  = \array_map( static fn( $t ) => $labels[ $t ] ?? $t, $app->appTypes() );
				echo esc_html( $names === [] ? '—' : \implode( ' · ', $names ) );
				break;

			case 'mua_version':
				echo '<code>' . esc_html( (string) $app->meta( 'app_version_name', '1.0.0' ) ) . '</code>';
				$code = (string) $app->meta( 'app_version_code', '' );
				if ( $code !== '' ) {
					echo ' <span class="mua-text-muted">(' . esc_html( $code ) . ')</span>';
				}
				break;

			case 'mua_screens':
				echo (int) \count( Screen::findByApp( $postId, [ 'fields' => 'ids', 'numberposts' => 200 ] ) );
				break;

			case 'mua_last_build':
				$status = (string) $app->meta( 'last_build_status', '' );
				$when   = (string) $app->meta( 'last_build_at', '' );
				if ( $status === '' ) {
					echo '<span class="mua-text-muted">—</span>';
					break;
				}
				echo esc_html( $status );
				if ( $when !== '' ) {
					echo '<br><span class="mua-text-muted">' . esc_html( $when ) . '</span>';
				}
				break;
		}
	}

	/**
	 * @param array<string, string> $sortable
	 * @return array<string, string>
	 */
	public static function filterAppSortableColumns( array $sortable ): array {
		$sortable['mua_platforms']  = 'mua_platforms';
		$sortable['mua_last_build'] = 'mua_last_build';
		return $sortable;
	}

	public static function applyAppListOrderBy( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ( $query->get( 'post_type' ) ?? '' ) !== App::POST_TYPE ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		$map     = [
			'mua_platforms'  => '_mua_app_types',
			'mua_last_build' => '_mua_last_build_at',
		];
		if ( \is_string( $orderby ) && isset( $map[ $orderby ] ) ) {
			$query->set( 'meta_key', $map[ $orderby ] );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	// ---------------------------------------------------------------------
	// mua_app edit screen — metaboxes
	// ---------------------------------------------------------------------

	public static function registerAppMetaboxes(): void {
		add_meta_box(
			'mua_app_identity',
			__( 'App Identity', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppIdentityMetaBox' ],
			App::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'mua_app_screens',
			__( 'Screens & Navigation', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppScreensMetaBox' ],
			App::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mua_app_branding',
			__( 'Branding', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppBrandingMetaBox' ],
			App::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'mua_app_ship_readiness',
			__( 'Ship Readiness', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppShipReadinessMetaBox' ],
			App::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mua_app_distribution',
			__( 'Distribution & Builds', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppDistributionMetaBox' ],
			App::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'mua_app_api_key',
			__( 'Shell API Key', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppApiKeyMetaBox' ],
			App::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'mua_app_native_identity',
			__( 'Native Store Identity', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppNativeIdentityMetaBox' ],
			App::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'mua_app_deeplink',
			__( 'Deep Linking', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppDeeplinkMetaBox' ],
			App::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'mua_app_fallback_policy',
			__( 'Fallback policy', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppFallbackPolicyMetaBox' ],
			App::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'mua_app_bifrost',
			__( 'Bifrost Setup', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppBifrostMetaBox' ],
			App::POST_TYPE,
			'normal',
			'low'
		);

		add_meta_box(
			'mua_app_extensions',
			__( 'Extensions', 'mustuse-apps-pub' ),
			[ self::class, 'renderAppExtensionsMetaBox' ],
			App::POST_TYPE,
			'normal',
			'low'
		);
	}

	public static function renderAppIdentityMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		wp_nonce_field( 'mua_save_app_' . $post->ID, self::APP_NONCE );

		$currentTypes = $app ? $app->appTypes() : App::DEFAULT_APP_TYPES;
		$platforms    = [
			'mobile_ios'     => __( 'iOS', 'mustuse-apps-pub' ),
			'mobile_android' => __( 'Android', 'mustuse-apps-pub' ),
		];
		$versionName  = $app ? (string) $app->meta( 'app_version_name', '1.0.0' ) : '1.0.0';
		$versionCode  = $app ? (string) $app->meta( 'app_version_code', '1' ) : '1';
		$slug         = $app ? $app->slug() : '';
		$shipped      = $app && (
			(string) $app->meta( 'last_build_at', '' ) !== ''
			|| (int) $app->meta( 'app_version_code', 1 ) > 1
		);
		?>
		<p>
			<label for="mua_app_slug"><strong><?php esc_html_e( 'Slug', 'mustuse-apps-pub' ); ?></strong></label>
			<input type="text" id="mua_app_slug" name="mua_app_slug" class="widefat code"
				value="<?php echo esc_attr( $slug ); ?>" placeholder="my-app" pattern="[a-z0-9-]+" <?php disabled( $shipped ); ?>>
		</p>
		<p class="description">
			<?php if ( $shipped ) : ?>
				<?php esc_html_e( 'Locked — already shipped. The slug bakes into the store bundle id and manifest URL; renaming would orphan every install.', 'mustuse-apps-pub' ); ?>
			<?php else : ?>
				<?php
				/* translators: %s: example bundle id */
				\printf( esc_html__( 'Becomes the bundle id %s. Pick before first Ship — locked afterwards.', 'mustuse-apps-pub' ), '<code>com.mustuse.' . esc_html( $slug !== '' ? $slug : 'my-app' ) . '</code>' );
				?>
			<?php endif; ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Platforms', 'mustuse-apps-pub' ); ?></strong><br>
			<?php foreach ( $platforms as $value => $label ) : ?>
				<label style="display:block; margin-top:4px;">
					<input type="checkbox" name="mua_app_types[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( \in_array( $value, $currentTypes, true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Select at least one target platform. Desktop targets are not yet supported.', 'mustuse-apps-pub' ); ?>
		</p>
		<p>
			<label
				for="mua_app_version_name"><strong><?php esc_html_e( 'Version (SemVer)', 'mustuse-apps-pub' ); ?></strong></label>
			<input type="text" id="mua_app_version_name" name="mua_app_version_name" class="widefat"
				value="<?php echo esc_attr( $versionName ); ?>" placeholder="1.0.0" pattern="\d+\.\d+\.\d+(-[a-zA-Z0-9.\-]+)?">
		</p>
		<p class="description">
			<?php
			/* translators: %s: current build code value */
			\printf( esc_html__( 'Build code auto-increments on every Ship. Current: %s', 'mustuse-apps-pub' ), '<code>' . esc_html( $versionCode ) . '</code>' );
			?>
		</p>
		<?php if ( $app instanceof App ) :
			$previewUrl = \MustUse\Pub\Preview\PreviewRoute::previewUrlFor( $post->ID );
			?>
			<hr>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $previewUrl ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Open preview ↗', 'mustuse-apps-pub' ); ?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'Opens a device-frame preview in a new tab. Honors the latest autosave.', 'mustuse-apps-pub' ); ?>
			</p>
		<?php endif; ?>
	<?php
	}

	/**
	 * Renders the screen tree manager. Server-side draws the current
	 * hierarchy as nested <ol>s; the editorial JS bundle hydrates SortableJS
	 * onto those lists so authors can drag-reorder, drag-nest, create, and
	 * delete without leaving the App edit screen.
	 *
	 * Tree mutations POST to ScreensTreeRoute (REST). The Save button on
	 * this metabox does nothing — every change persists immediately so a
	 * close-tab mid-session never loses an arrangement.
	 */
	public static function renderAppScreensMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		if ( ! $app instanceof App ) {
			echo '<p>' . esc_html__( 'Save the app first to add screens.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		$screens  = Screen::findByApp( $app->id(), [ 'post_status' => 'any' ] );
		$byParent = [];
		foreach ( $screens as $screen ) {
			$byParent[ $screen->parentId()][] = $screen;
		}

		?>
		<div class="mua-screen-tree" data-app-id="<?php echo esc_attr( (string) $app->id() ); ?>">
			<div class="mua-screen-tree__toolbar">
				<button type="button" class="button button-primary mua-screen-tree__add" data-parent-id="0">
					+ <?php esc_html_e( 'Add Screen', 'mustuse-apps-pub' ); ?>
				</button>
				<button type="button" class="button mua-screen-tree__bulk-toggle">
					<?php esc_html_e( 'Select…', 'mustuse-apps-pub' ); ?>
				</button>
				<button type="button" class="button button-link-delete mua-screen-tree__bulk-delete" hidden>
					<?php esc_html_e( 'Delete selected', 'mustuse-apps-pub' ); ?>
					<span class="mua-screen-tree__bulk-count"></span>
				</button>
				<span class="mua-screen-tree__hint">
					<?php esc_html_e( 'Drag to reorder, drag onto another screen to nest it as a child.', 'mustuse-apps-pub' ); ?>
				</span>
				<span class="mua-screen-tree__status" aria-live="polite"></span>
			</div>

			<?php self::renderScreenList( $byParent, 0 ); ?>

			<?php if ( empty( $screens ) ) : ?>
				<p class="mua-screen-tree__empty">
					<?php esc_html_e( 'No screens yet. Click Add Screen to create the first one.', 'mustuse-apps-pub' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, list<Screen>> $byParent
	 */
	private static function renderScreenList( array $byParent, int $parentId ): void {
		$children = $byParent[ $parentId ] ?? [];
		?>
		<ol class="mua-screen-tree__list" data-parent-id="<?php echo esc_attr( (string) $parentId ); ?>">
			<?php foreach ( $children as $screen ) :
				$editUrl = (string) get_edit_post_link( $screen->id(), 'raw' );
				?>
				<li class="mua-screen-tree__item" data-screen-id="<?php echo esc_attr( (string) $screen->id() ); ?>">
					<div class="mua-screen-tree__row">
						<span class="mua-screen-tree__handle" aria-hidden="true">⋮⋮</span>
						<label class="mua-screen-tree__select" hidden>
							<input type="checkbox" class="mua-screen-tree__select-checkbox"
								data-screen-id="<?php echo esc_attr( (string) $screen->id() ); ?>"
								data-screen-title="<?php echo esc_attr( $screen->title() ); ?>">
							<span class="screen-reader-text">
								<?php
								/* translators: %s: screen title */
								echo esc_html( \sprintf( __( 'Select %s for bulk delete', 'mustuse-apps-pub' ), $screen->title() ) );
								?>
							</span>
						</label>
						<a class="mua-screen-tree__title" href="<?php echo esc_url( $editUrl ); ?>">
							<?php echo esc_html( $screen->title() !== '' ? $screen->title() : __( '(untitled)', 'mustuse-apps-pub' ) ); ?>
						</a>
						<code class="mua-screen-tree__slug"><?php echo esc_html( $screen->slug() ); ?></code>
						<span
							class="mua-screen-tree__status-badge mua-screen-tree__status-badge--<?php echo esc_attr( $screen->status() ); ?>">
							<?php echo esc_html( \ucfirst( $screen->status() ) ); ?>
						</span>
						<span class="mua-screen-tree__actions">
							<button type="button" class="button-link mua-screen-tree__add"
								data-parent-id="<?php echo esc_attr( (string) $screen->id() ); ?>">
								+ <?php esc_html_e( 'Child', 'mustuse-apps-pub' ); ?>
							</button>
							<a class="button-link" href="<?php echo esc_url( $editUrl ); ?>">
								<?php esc_html_e( 'Edit', 'mustuse-apps-pub' ); ?>
							</a>
							<a class="button-link"
								href="<?php echo esc_url( \MustUse\Pub\Preview\PreviewRoute::previewUrlFor( $screen->id() ) ); ?>"
								target="_blank" rel="noopener">
								<?php esc_html_e( 'Preview', 'mustuse-apps-pub' ); ?>
							</a>
							<button type="button" class="button-link mua-screen-tree__delete"
								data-screen-title="<?php echo esc_attr( $screen->title() ); ?>">
								<?php esc_html_e( 'Delete', 'mustuse-apps-pub' ); ?>
							</button>
						</span>
					</div>
					<?php self::renderScreenList( $byParent, $screen->id() ); ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	public static function renderAppBrandingMetaBox( \WP_Post $post ): void {
		$app      = App::find( $post->ID );
		$branding = $app ? $app->meta( 'branding', [] ) : [];
		if ( ! \is_array( $branding ) ) {
			$branding = [];
		}
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label
						for="mua_branding_primary_color"><?php esc_html_e( 'Primary Color', 'mustuse-apps-pub' ); ?></label>
				</th>
				<td>
					<input type="color" id="mua_branding_primary_color" name="mua_branding_primary_color"
						value="<?php echo esc_attr( (string) ( $branding['primary_color'] ?? '#1e1e1e' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label
						for="mua_branding_accent_color"><?php esc_html_e( 'Accent Color', 'mustuse-apps-pub' ); ?></label></th>
				<td>
					<input type="color" id="mua_branding_accent_color" name="mua_branding_accent_color"
						value="<?php echo esc_attr( (string) ( $branding['accent_color'] ?? '#2271b1' ) ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label
						for="mua_branding_background_color"><?php esc_html_e( 'Background Color', 'mustuse-apps-pub' ); ?></label>
				</th>
				<td>
					<input type="color" id="mua_branding_background_color" name="mua_branding_background_color"
						value="<?php echo esc_attr( (string) ( $branding['background_color'] ?? '#ffffff' ) ); ?>">
				</td>
			</tr>
			<?php foreach ( [
				'icon_url'   => __( 'App Icon', 'mustuse-apps-pub' ),
				'splash_url' => __( 'Splash', 'mustuse-apps-pub' ),
				'logo_url'   => __( 'Logo', 'mustuse-apps-pub' ),
			] as $key => $label ) :
				$current = (string) ( $branding[ $key ] ?? '' );
				$id      = 'mua_branding_' . $key;
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td class="mua-media-field" data-target="<?php echo esc_attr( $id ); ?>">
						<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>"
							value="<?php echo esc_attr( $current ); ?>">
						<?php if ( $current !== '' ) : ?>
							<img src="<?php echo esc_url( $current ); ?>" class="mua-media-preview" alt=""
								style="max-width:120px;display:block;margin-bottom:8px;">
						<?php endif; ?>
						<button type="button" class="button mua-media-upload-btn">
							<?php esc_html_e( 'Choose Image', 'mustuse-apps-pub' ); ?>
						</button>
						<button type="button" class="button mua-media-remove-btn" <?php echo $current === '' ? 'style="display:none"' : ''; ?>>
							<?php esc_html_e( 'Remove', 'mustuse-apps-pub' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	public static function renderAppShipReadinessMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		if ( ! $app ) {
			echo '<p>' . esc_html__( 'Save the app to run validation.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		$manifestErrors = [];
		$auditFindings  = [];
		$manifest       = NULL;

		try {
			$manifest       = ( new \MustUse\Pub\Manifest\ManifestBuilder() )->build( $app );
			$manifestErrors = ( new \MustUse\Pub\Manifest\ManifestValidator() )->validate( $manifest, $app );
		} catch (\Throwable $e) {
			$manifestErrors = [
				\sprintf(
					/* translators: %s: exception message */
					__( 'Manifest build failed: %s', 'mustuse-apps-pub' ),
					$e->getMessage()
				),
			];
		}

		try {
			$sources       = ( new \MustUse\Pub\Support\BuildAssembler() )->blockSources();
			$auditFindings = \MustUse\Pub\Blocks\BlockSchemaAuditor::audit( $sources );
		} catch (\Throwable $e) {
			$auditFindings = [
				[
					'block'  => '',
					'issue'  => 'audit_failed',
					'detail' => $e->getMessage(),
				],
			];
		}

		$clean = empty( $manifestErrors ) && empty( $auditFindings );
		?>
		<div class="mua-ship-readiness">
			<?php if ( $clean ) : ?>
				<p style="padding:10px; background:#edf7ed; border-left:4px solid #1f7a3f; margin:0;">
					<strong><?php esc_html_e( 'Ready to ship.', 'mustuse-apps-pub' ); ?></strong>
					<?php esc_html_e( 'Manifest validates and every block\'s Blade renderer matches its schema.', 'mustuse-apps-pub' ); ?>
				</p>
			<?php else : ?>
				<p style="padding:10px; background:#fdecea; border-left:4px solid #b0264c; margin:0 0 12px 0;">
					<strong><?php esc_html_e( 'Fix these before shipping.', 'mustuse-apps-pub' ); ?></strong>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $manifestErrors ) ) : ?>
				<h4 style="margin:12px 0 6px;"><?php esc_html_e( 'Manifest validator', 'mustuse-apps-pub' ); ?></h4>
				<ul style="list-style:disc; padding-left:20px;">
					<?php foreach ( $manifestErrors as $error ) : ?>
						<li><?php echo esc_html( (string) $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $auditFindings ) ) : ?>
				<h4 style="margin:12px 0 6px;"><?php esc_html_e( 'Block schema audit', 'mustuse-apps-pub' ); ?></h4>
				<ul style="list-style:disc; padding-left:20px;">
					<?php foreach ( $auditFindings as $finding ) :
						if ( ! \is_array( $finding ) ) {
							continue;
						}
						$block  = (string) ( $finding['block'] ?? '' );
						$issue  = (string) ( $finding['issue'] ?? '' );
						$detail = (string) ( $finding['detail'] ?? '' );
						?>
						<li>
							<?php if ( $block !== '' ) : ?>
								<code><?php echo esc_html( $block ); ?></code> —
							<?php endif; ?>
							<?php echo esc_html( $issue ); ?>
							<?php if ( $detail !== '' ) : ?>
								: <code><?php echo esc_html( $detail ); ?></code>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $manifest !== NULL ) :
				$screenCount = \is_array( $manifest['screens'] ?? NULL ) ? \count( $manifest['screens'] ) : 0;
				$navCount    = \is_array( $manifest['navigation']['bottom_nav'] ?? NULL ) ? \count( $manifest['navigation']['bottom_nav'] ) : 0;
				$hasHome     = false;
				foreach ( (array) ( $manifest['screens'] ?? [] ) as $screen ) {
					if ( \is_array( $screen ) && ! empty( $screen['is_home'] ) ) {
						$hasHome = true;
						break;
					}
				}
				?>
				<p style="margin-top:12px; font-size:12px; color:#555;">
					<?php
					echo esc_html( \sprintf(
						/* translators: 1: screen count 2: nav count */
						__( '%1$d screens · %2$d bottom-nav tabs', 'mustuse-apps-pub' ),
						$screenCount,
						$navCount
					) );
					?>
					<?php if ( $screenCount > 0 && ! $hasHome ) : ?>
						<br><strong style="color:#a55;">
							<?php esc_html_e( 'No home screen flagged — the shell will fall back to the first nav-visible screen. Set "Use as home" on one screen to make routing deterministic.', 'mustuse-apps-pub' ); ?>
						</strong>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function renderAppDistributionMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		if ( ! $app instanceof App ) {
			echo '<p>' . esc_html__( 'Save the app first to configure distribution.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		$repoUrl     = (string) $app->meta( 'build_repo_url', '' );
		$hasToken    = (string) $app->meta( 'github_token', '' ) !== '';
		$buildStatus = (string) $app->meta( 'last_build_status', '' );
		$buildAt     = (string) $app->meta( 'last_build_at', '' );
		$buildError  = (string) $app->meta( 'last_build_error', '' );
		$history     = $app->meta( 'ship_history', [] );
		if ( ! \is_array( $history ) ) {
			$history = [];
		}
		?>
		<h3><?php esc_html_e( 'GitHub Repository', 'mustuse-apps-pub' ); ?></h3>
		<p>
			<label
				for="mua_build_repo_url"><strong><?php esc_html_e( 'Repository URL', 'mustuse-apps-pub' ); ?></strong></label>
			<input type="text" id="mua_build_repo_url" name="mua_build_repo_url" class="widefat"
				value="<?php echo esc_attr( $repoUrl ); ?>" placeholder="https://github.com/org/my-app.git">
		</p>
		<p>
			<label
				for="mua_github_token"><strong><?php esc_html_e( 'GitHub Personal Access Token', 'mustuse-apps-pub' ); ?></strong></label>
			<input type="password" id="mua_github_token" name="mua_github_token" class="widefat" autocomplete="new-password"
				value=""
				placeholder="<?php echo $hasToken ? esc_attr__( '(stored — enter new to replace)', 'mustuse-apps-pub' ) : ''; ?>">
		</p>
		<p class="description">
			<?php esc_html_e( 'Fine-grained PAT with Contents: Read/Write on this repo. Stored encrypted per-app.', 'mustuse-apps-pub' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="mua-test-connection"
				data-app-id="<?php echo esc_attr( (string) $app->id() ); ?>">
				<?php esc_html_e( 'Test Connection', 'mustuse-apps-pub' ); ?>
			</button>
			<span id="mua-test-result" style="margin-left:8px;font-weight:bold;display:none;"></span>
		</p>

		<h3 style="margin-top:24px;"><?php esc_html_e( 'Deployment Pipeline', 'mustuse-apps-pub' ); ?></h3>
		<?php include MUA_PUB_DIR . 'views/editorial/_deployment-map.php'; ?>

		<h3 style="margin-top:24px;"><?php esc_html_e( 'Build & Deploy', 'mustuse-apps-pub' ); ?></h3>
		<div id="mua-build-status" data-app-id="<?php echo esc_attr( (string) $app->id() ); ?>"
			data-status="<?php echo esc_attr( $buildStatus ); ?>">
			<?php if ( $buildStatus !== '' && $buildStatus !== 'failed' ) : ?>
				<p>
					<strong><?php esc_html_e( 'Status:', 'mustuse-apps-pub' ); ?></strong>
					<span class="mua-build-status__label"><?php echo esc_html( \ucfirst( $buildStatus ) ); ?></span>
					<span class="mua-build-status__when mua-text-muted">
						<?php echo $buildAt !== '' ? ' — ' . esc_html( $buildAt ) : ''; ?>
					</span>
				</p>
			<?php endif; ?>
			<div class="notice notice-error inline mua-build-status__error" <?php echo $buildError === '' ? 'hidden' : ''; ?>>
				<p><?php echo esc_html( $buildError ); ?></p>
			</div>
		</div>
		<p>
			<button type="button" class="button button-primary" id="mua-ship-it"
				data-app-id="<?php echo esc_attr( (string) $app->id() ); ?>" data-ship-target="all">
				<?php esc_html_e( 'Ship It!', 'mustuse-apps-pub' ); ?>
			</button>
		</p>
		<p class="description">
			<?php esc_html_e( 'Dispatches an isolated projection to your GitHub repository before transit processing begins.', 'mustuse-apps-pub' ); ?>
		</p>

		<div id="mua-ship-success" class="mua-next-steps" hidden>
			<h4><?php esc_html_e( 'Next Steps — Finish in Bifrost', 'mustuse-apps-pub' ); ?></h4>
			<p>
				<a id="mua-compare-link" href="#" target="_blank" rel="noopener" class="button button-primary" hidden>
					<?php esc_html_e( 'Review Projected Code →', 'mustuse-apps-pub' ); ?>
				</a>
				<span id="mua-compare-pending" class="mua-text-muted">
					<?php esc_html_e( '(branch link appears once projection completes)', 'mustuse-apps-pub' ); ?>
				</span>
			</p>
			<table class="widefat mua-env-table" id="mua-env-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'mustuse-apps-pub' ); ?></th>
						<th><?php esc_html_e( 'Value', 'mustuse-apps-pub' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>

		<pre class="mua-terminal" id="mua-build-terminal" hidden></pre>

		<h3 style="margin-top:24px;"><?php esc_html_e( 'Ship History', 'mustuse-apps-pub' ); ?></h3>
		<table class="widefat striped" id="mua-ship-history">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'mustuse-apps-pub' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mustuse-apps-pub' ); ?></th>
					<th><?php esc_html_e( 'Branch', 'mustuse-apps-pub' ); ?></th>
					<th><?php esc_html_e( 'Outcome', 'mustuse-apps-pub' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'mustuse-apps-pub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $history ) ) : ?>
					<tr>
						<td colspan="5" class="mua-text-muted"><?php esc_html_e( 'No ship history yet.', 'mustuse-apps-pub' ); ?>
						</td>
					</tr>
				<?php else :
					foreach ( $history as $entry ) :
						$status = (string) ( $entry['status'] ?? 'unknown' );
						?>
						<tr>
							<td><?php echo esc_html( (string) ( $entry['ts'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $status ); ?></td>
							<td><?php echo empty( $entry['branch'] ) ? '—' : '<code>' . esc_html( (string) $entry['branch'] ) . '</code>'; ?>
							</td>
							<td>
								<?php if ( ! empty( $entry['compare_url'] ) ) : ?>
									<a href="<?php echo esc_url( (string) $entry['compare_url'] ); ?>" target="_blank" rel="noopener">
										<?php echo (int) ( $entry['files_committed'] ?? 0 ); ?> file(s) →
									</a>
								<?php elseif ( ! empty( $entry['error'] ) ) : ?>
									<span class="mua-text-error"><?php echo esc_html( (string) $entry['error'] ); ?></span>
								<?php else : ?>—<?php endif; ?>
							</td>
							<td><?php echo (int) ( $entry['duration_ms'] ?? 0 ); ?>ms</td>
						</tr>
					<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	public static function renderAppApiKeyMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		if ( ! $app instanceof App ) {
			echo '<p>' . esc_html__( 'Save the app first to manage its API key.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		$hasKey = AppKeyManager::hasKey( $app );
		$prefix = AppKeyManager::getPrefix( $app );
		$newKey = get_transient( 'mua_new_api_key_' . $app->id() );
		if ( $newKey ) {
			delete_transient( 'mua_new_api_key_' . $app->id() );
		}
		?>
		<?php if ( $newKey ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 12px;">
				<p><strong><?php esc_html_e( 'Copy now — it will not be shown again:', 'mustuse-apps-pub' ); ?></strong></p>
				<code style="word-break:break-all;display:block;"><?php echo esc_html( (string) $newKey ); ?></code>
			</div>
		<?php endif; ?>

		<?php if ( $hasKey ) : ?>
			<p><?php esc_html_e( 'Active prefix:', 'mustuse-apps-pub' ); ?> <code><?php echo esc_html( $prefix ); ?>…</code></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No API key yet. Required before shipping.', 'mustuse-apps-pub' ); ?></p>
		<?php endif; ?>

		<p>
			<label>
				<input type="checkbox" name="mua_generate_api_key" value="1">
				<?php echo $hasKey
					? esc_html__( 'Rotate key on save', 'mustuse-apps-pub' )
					: esc_html__( 'Generate key on save', 'mustuse-apps-pub' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Rotating will break any currently-authenticated shell until the new key is deployed.', 'mustuse-apps-pub' ); ?>
		</p>
		<?php
	}

	/**
	 * Native store identity — bundle id, Apple Team ID, Android package
	 * name, signing-cert SHA fingerprints. These feed the well-known
	 * verification files that Apple and Google fetch, so they
	 * must match the publisher's actual App Store / Play Console entries.
	 */
	public static function renderAppNativeIdentityMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		if ( ! $app instanceof App ) {
			echo '<p>' . esc_html__( 'Save the app first to configure native identity.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		$bundleId       = (string) $app->meta( 'bundle_id', '' );
		$appleTeamId    = (string) $app->meta( 'apple_team_id', '' );
		$androidPackage = (string) $app->meta( 'android_package_name', '' );
		$androidSha     = (array) $app->meta( 'android_sha256_fingerprints', [] );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="mua_bundle_id"><?php esc_html_e( 'iOS Bundle ID', 'mustuse-apps-pub' ); ?></label>
				</th>
				<td>
					<input type="text" id="mua_bundle_id" name="mua_bundle_id" class="regular-text"
						value="<?php echo esc_attr( $bundleId ); ?>" placeholder="com.acmenews.reader"
						pattern="[a-z0-9][a-z0-9.\-]*[a-z0-9]" maxlength="253">
					<p class="description">
						<?php esc_html_e( 'Reverse-DNS identifier matching the App Store Connect entry.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label
						for="mua_apple_team_id"><?php esc_html_e( 'Apple Team ID', 'mustuse-apps-pub' ); ?></label></th>
				<td>
					<input type="text" id="mua_apple_team_id" name="mua_apple_team_id"
						value="<?php echo esc_attr( $appleTeamId ); ?>" pattern="[A-Z0-9]{10}" maxlength="10"
						style="text-transform:uppercase;width:140px;">
					<p class="description">
						<?php esc_html_e( '10-character team identifier from Apple Developer membership.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label
						for="mua_android_package"><?php esc_html_e( 'Android Package', 'mustuse-apps-pub' ); ?></label></th>
				<td>
					<input type="text" id="mua_android_package" name="mua_android_package_name" class="regular-text"
						value="<?php echo esc_attr( $androidPackage ); ?>" placeholder="com.acmenews.reader"
						pattern="[a-z0-9][a-z0-9.\-]*[a-z0-9]" maxlength="253">
				</td>
			</tr>
			<tr>
				<th scope="row"><label
						for="mua_android_sha"><?php esc_html_e( 'Android Cert Fingerprints', 'mustuse-apps-pub' ); ?></label>
				</th>
				<td>
					<textarea id="mua_android_sha" name="mua_android_sha256_fingerprints" rows="4" class="large-text code"
						placeholder="AA:BB:CC:..."><?php echo esc_textarea( \implode( "\n", \array_filter( $androidSha, '\is_string' ) ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'SHA-256 fingerprints, one per line. Include both the upload key and the Play Signing key.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Deep link configuration. Scheme + host feed the
	 * well-known apple-app-site-association and assetlinks.json files
	 * that Apple and Google fetch to verify universal links.
	 */
	public static function renderAppDeeplinkMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		if ( ! $app instanceof App ) {
			echo '<p>' . esc_html__( 'Save the app first to configure deep links.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		$scheme = (string) $app->meta( 'deeplink_scheme', '' );
		$host   = (string) $app->meta( 'deeplink_host', '' );
		?>
		<p>
			<label for="mua_deeplink_scheme"><strong><?php esc_html_e( 'URL Scheme', 'mustuse-apps-pub' ); ?></strong></label>
			<input type="text" id="mua_deeplink_scheme" name="mua_deeplink_scheme" class="widefat"
				value="<?php echo esc_attr( $scheme ); ?>" placeholder="acmenews" pattern="[a-z][a-z0-9]*" maxlength="32">
		</p>
		<p class="description">
			<?php esc_html_e( 'Lowercase. e.g. "acmenews" → acmenews://article/123', 'mustuse-apps-pub' ); ?>
		</p>
		<p>
			<label for="mua_deeplink_host"><strong><?php esc_html_e( 'Verified Host', 'mustuse-apps-pub' ); ?></strong></label>
			<input type="text" id="mua_deeplink_host" name="mua_deeplink_host" class="widefat"
				value="<?php echo esc_attr( $host ); ?>" placeholder="app.acmenews.com" maxlength="253">
		</p>
		<p class="description">
			<?php esc_html_e( 'Domain that owns the app. Must serve the well-known files this plugin provides.', 'mustuse-apps-pub' ); ?>
		</p>
		<?php
	}

	/**
	 * Read-only Bifrost setup reference. We deliberately do not store
	 * secret values — Bifrost owns build-time secrets. This panel just
	 * shows publishers the env-var NAMES (and non-secret values) they
	 * need to paste into Bifrost's Environment Variables UI, sourced
	 * from {@see \MustUse\Pub\Support\BuildAssembler::envForBifrost}.
	 */
	public static function renderAppBifrostMetaBox( \WP_Post $post ): void {
		$app = App::find( $post->ID );
		if ( ! $app instanceof App ) {
			echo '<p>' . esc_html__( 'Save the app first to see Bifrost setup details.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		$envRows = ( new \MustUse\Pub\Support\BuildAssembler() )->envForBifrost( $app );
		?>
		<p class="description">
			<?php esc_html_e( 'Paste these into Bifrost\'s Environment Variables screen. Secret values are owned by Bifrost — they never leave WordPress and they\'re never stored here.', 'mustuse-apps-pub' ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'mustuse-apps-pub' ); ?></th>
					<th><?php esc_html_e( 'Value', 'mustuse-apps-pub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $envRows as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) $row['name'] ); ?></code><?php echo ! empty( $row['secret'] ) ? ' 🔒' : ''; ?>
						</td>
						<td>
							<?php if ( ! empty( $row['secret'] ) ) : ?>
								<em><?php esc_html_e( '— set in Bifrost UI —', 'mustuse-apps-pub' ); ?></em>
							<?php elseif ( ( $row['value'] ?? '' ) === '' ) : ?>
								<em><?php esc_html_e( '(empty — configure earlier in this editor)', 'mustuse-apps-pub' ); ?></em>
							<?php else : ?>
								<code><?php echo esc_html( (string) $row['value'] ); ?></code>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function renderAppFallbackPolicyMetaBox( \WP_Post $post ): void {
		wp_nonce_field( 'mua_save_fallback_policy', '_mua_fallback_policy_nonce' );
		$app      = App::find( $post->ID );
		$mode     = $app instanceof App ? (string) $app->meta( 'fallback_policy', 'web' ) : 'web';
		$comments = $app instanceof App ? (bool) $app->meta( 'enable_comments', false ) : false;
		?>
		<p>
			<label>
				<input type="checkbox" name="mua_enable_comments" value="1" <?php checked( $comments ); ?>>
				<strong><?php esc_html_e( 'Enable comments', 'mustuse-apps-pub' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'Exposes /comments/{post_id} to the shell so post-comments blocks render. Individual posts still honour their comment_status.', 'mustuse-apps-pub' ); ?></span>
			</label>
		</p>
		<hr>
		<p>
			<label>
				<input type="radio" name="mua_fallback_policy" value="web" <?php checked( $mode, 'web' ); ?>>
				<strong><?php esc_html_e( 'Web view (default)', 'mustuse-apps-pub' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'When a deep link resolves to content with no native template and no cascade match, open the WP URL in an in-app browser.', 'mustuse-apps-pub' ); ?></span>
			</label>
		</p>
		<p>
			<label>
				<input type="radio" name="mua_fallback_policy" value="screen_404" <?php checked( $mode, 'screen_404' ); ?>>
				<strong><?php esc_html_e( 'Authored 404 screen', 'mustuse-apps-pub' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'Always render the screen you flagged as "404 — catch-all fallback". Requires one.', 'mustuse-apps-pub' ); ?></span>
			</label>
		</p>
		<p>
			<label>
				<input type="radio" name="mua_fallback_policy" value="home" <?php checked( $mode, 'home' ); ?>>
				<strong><?php esc_html_e( 'Route to home', 'mustuse-apps-pub' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'Bounce unresolvable paths to /.  Loses context but simplest behaviour.', 'mustuse-apps-pub' ); ?></span>
			</label>
		</p>
		<?php
	}

	public static function renderAppExtensionsMetaBox( \WP_Post $post ): void {
		$app              = App::find( $post->ID );
		$extensions       = ExtensionRegistry::all();
		$activeExtensions = $app ? ( $app->meta( 'active_extensions', [] ) ?: [] ) : [];
		if ( ! \is_array( $activeExtensions ) ) {
			$activeExtensions = [];
		}

		if ( empty( $extensions ) ) {
			echo '<p class="description">' . esc_html__( 'No extensions registered. Third-party plugins can register via mua_register_extension().', 'mustuse-apps-pub' ) . '</p>';
			return;
		}
		?>
		<ul class="mua-extension-list">
			<?php foreach ( $extensions as $extSlug => $ext ) : ?>
				<li style="padding:6px 0;border-bottom:1px solid #eee;">
					<label>
						<input type="checkbox" name="mua_active_extensions[]" value="<?php echo esc_attr( (string) $extSlug ); ?>"
							<?php checked( \in_array( $extSlug, $activeExtensions, true ) ); ?>>
						<strong><?php echo esc_html( (string) $ext['plugin_name'] ); ?></strong>
						<span class="mua-text-muted">v<?php echo esc_html( (string) $ext['plugin_version'] ); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Persist all mua_app metabox fields on save.
	 *
	 * Writes every persisted meta key in one pass rather than splitting per
	 * metabox — keeps the read-side meta contract identical (`_mua_branding`,
	 * `_mua_build_repo_url`, `_mua_active_extensions`, `_mua_app_types`,
	 * `_mua_app_version_name`) so ManifestBuilder, BuildAssembler, and
	 * ShipRoute need no changes.
	 */
	public static function saveAppMetaboxes( int $postId, \WP_Post $post ): void {
		if ( $post->post_type !== App::POST_TYPE ) {
			return;
		}
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}
		if ( $post->post_status === 'auto-draft' ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::APP_NONCE ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::APP_NONCE ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'mua_save_app_' . $postId ) ) {
			return;
		}

		$app = App::find( $postId );
		if ( ! $app instanceof App ) {
			return;
		}

		if ( \array_key_exists( 'mua_app_slug', $_POST ) ) {
			$desired = sanitize_title( (string) $_POST['mua_app_slug'] );
			if ( $desired !== '' && $desired !== $post->post_name ) {
				remove_action( 'save_post_' . App::POST_TYPE, [ self::class, 'saveAppMetaboxes' ], 10 );
				wp_update_post( [ 'ID' => $postId, 'post_name' => $desired ] );
				add_action( 'save_post_' . App::POST_TYPE, [ self::class, 'saveAppMetaboxes' ], 10, 2 );
			}
		}

		$rawTypes = $_POST['mua_app_types'] ?? [];
		$types    = \array_values( \array_filter( \array_map(
			static fn( $t ) => \is_string( $t ) ? sanitize_key( $t ) : '',
			(array) $rawTypes
		), static fn( string $t ) => $t !== '' ) );
		if ( App::isSupportedAppTypes( $types ) ) {
			$app->setAppTypes( $types );
		}

		$versionName = sanitize_text_field( (string) ( $_POST['mua_app_version_name'] ?? '' ) );
		if ( $versionName !== '' && \preg_match( '/^\d+\.\d+\.\d+(-[a-z0-9.-]+)?$/i', $versionName ) ) {
			$app->updateMeta( 'app_version_name', $versionName );
		}

		// Branding.
		if ( \array_key_exists( 'mua_branding_primary_color', $_POST ) ) {
			$branding = [
				'icon_url'         => esc_url_raw( (string) ( $_POST['mua_branding_icon_url'] ?? '' ) ),
				'logo_url'         => esc_url_raw( (string) ( $_POST['mua_branding_logo_url'] ?? '' ) ),
				'splash_url'       => esc_url_raw( (string) ( $_POST['mua_branding_splash_url'] ?? '' ) ),
				'primary_color'    => sanitize_hex_color( (string) ( $_POST['mua_branding_primary_color'] ?? '' ) ) ?: '',
				'accent_color'     => sanitize_hex_color( (string) ( $_POST['mua_branding_accent_color'] ?? '' ) ) ?: '',
				'background_color' => sanitize_hex_color( (string) ( $_POST['mua_branding_background_color'] ?? '' ) ) ?: '',
			];
			$app->updateMeta( 'branding', $branding );
		}

		// Distribution — repo URL is cleartext, token is encrypted only when
		// a new value arrives (empty field means "leave stored token alone").
		if ( \array_key_exists( 'mua_build_repo_url', $_POST ) ) {
			$app->updateMeta( 'build_repo_url', sanitize_text_field( (string) $_POST['mua_build_repo_url'] ) );
		}
		if ( ! empty( $_POST['mua_github_token'] ) ) {
			$app->updateMeta( 'github_token', SecureStorage::encrypt( (string) $_POST['mua_github_token'] ) );
		}

		// Native store identity. Each value is validated; an empty / invalid
		// value clears the meta (rejecting on a per-field basis would surprise
		// authors who legitimately want to remove an outdated value).
		if ( \array_key_exists( 'mua_bundle_id', $_POST ) ) {
			$bundleId = sanitize_text_field( (string) $_POST['mua_bundle_id'] );
			if ( $bundleId !== '' && ! \preg_match( '/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/i', $bundleId ) ) {
				$bundleId = '';
			}
			$app->updateMeta( 'bundle_id', $bundleId );
		}
		if ( \array_key_exists( 'mua_apple_team_id', $_POST ) ) {
			$teamId = \strtoupper( sanitize_text_field( (string) $_POST['mua_apple_team_id'] ) );
			if ( $teamId !== '' && ! \preg_match( '/^[A-Z0-9]{10}$/', $teamId ) ) {
				$teamId = '';
			}
			$app->updateMeta( 'apple_team_id', $teamId );
		}
		if ( \array_key_exists( 'mua_android_package_name', $_POST ) ) {
			$pkg = sanitize_text_field( (string) $_POST['mua_android_package_name'] );
			if ( $pkg !== '' && ! \preg_match( '/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/i', $pkg ) ) {
				$pkg = '';
			}
			$app->updateMeta( 'android_package_name', $pkg );
		}
		if ( \array_key_exists( 'mua_android_sha256_fingerprints', $_POST ) ) {
			$lines        = \preg_split( '/\r?\n/', (string) $_POST['mua_android_sha256_fingerprints'] ) ?: [];
			$fingerprints = [];
			foreach ( $lines as $line ) {
				$line = \strtoupper( \trim( $line ) );
				if ( $line !== '' && \preg_match( '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $line ) ) {
					$fingerprints[] = $line;
				}
			}
			$app->updateMeta( 'android_sha256_fingerprints', $fingerprints );
		}

		// Deep linking.
		if ( \array_key_exists( 'mua_deeplink_scheme', $_POST ) ) {
			$scheme = sanitize_key( (string) $_POST['mua_deeplink_scheme'] );
			if ( $scheme !== '' && ! \preg_match( '/^[a-z][a-z0-9]*$/', $scheme ) ) {
				$scheme = '';
			}
			$app->updateMeta( 'deeplink_scheme', $scheme );
		}
		if ( \array_key_exists( 'mua_deeplink_host', $_POST ) ) {
			$host = sanitize_text_field( (string) $_POST['mua_deeplink_host'] );
			if ( $host !== '' && ! \preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host ) ) {
				$host = '';
			}
			$app->updateMeta( 'deeplink_host', $host );
		}

		// Extensions.
		$rawExtensions = $_POST['mua_active_extensions'] ?? [];
		$active        = [];
		if ( \is_array( $rawExtensions ) ) {
			$known = ExtensionRegistry::all();
			foreach ( $rawExtensions as $slug ) {
				$slug = sanitize_key( $slug );
				if ( isset( $known[ $slug ] ) ) {
					$active[] = $slug;
				}
			}
		}
		$app->updateMeta( 'active_extensions', $active );

		// API key generate/rotate (checkbox on the API Key metabox).
		if ( ! empty( $_POST['mua_generate_api_key'] ) ) {
			$plaintext = AppKeyManager::generate( $app );
			set_transient( 'mua_new_api_key_' . $postId, $plaintext, 60 );
		}

		// Fallback policy (for the shell cascade's floor).
		if ( \array_key_exists( 'mua_fallback_policy', $_POST ) ) {
			$mode    = \is_string( $_POST['mua_fallback_policy'] ) ? (string) $_POST['mua_fallback_policy'] : 'web';
			$allowed = [ 'web', 'screen_404', 'home' ];
			$app->updateMeta( 'fallback_policy', \in_array( $mode, $allowed, true ) ? $mode : 'web' );
		}

		// Comments toggle (saved from the Fallback policy metabox).
		if ( \array_key_exists( '_mua_fallback_policy_nonce', $_POST ) ) {
			$app->updateMeta( 'enable_comments', ! empty( $_POST['mua_enable_comments'] ) );
		}
	}

	// ---------------------------------------------------------------------
	// Screen metaboxes + orphan guards (native edit screens)
	// ---------------------------------------------------------------------

	private function registerPostScreenHooks(): void {
		// Orphan warning: screen without owning app.
		add_action( 'current_screen', static function (): void {
			$currentScreen = get_current_screen();
			if ( ! $currentScreen || $currentScreen->id !== Screen::POST_TYPE ) {
				return;
			}
			$postId = (int) ( $_GET['post'] ?? 0 );
			if ( $postId && ! get_post_meta( $postId, '_mua_app_id', true ) ) {
				add_action( 'admin_notices', static function (): void {
					echo '<div class="notice notice-warning"><p>';
					esc_html_e( 'This screen is not linked to an app.', 'mustuse-apps-pub' );
					echo '</p></div>';
				} );
			}
		} );

		// Screen metaboxes: Back to App, slug, deeplink, routing, nav.
		add_action( 'add_meta_boxes_' . Screen::POST_TYPE, static function (): void {
			add_meta_box(
				'mua_screen_app_link',
				__( 'App', 'mustuse-apps-pub' ),
				[ self::class, 'renderScreenAppMetaBox' ],
				Screen::POST_TYPE,
				'side',
				'high'
			);

			add_meta_box(
				'mua_screen_slug',
				__( 'Screen Slug', 'mustuse-apps-pub' ),
				[ self::class, 'renderScreenSlugMetaBox' ],
				Screen::POST_TYPE,
				'side',
				'high'
			);

			add_meta_box(
				'mua_screen_deeplink',
				__( 'Deep Link Path', 'mustuse-apps-pub' ),
				[ self::class, 'renderScreenDeeplinkMetaBox' ],
				Screen::POST_TYPE,
				'side',
				'default'
			);

			add_meta_box(
				'mua_screen_routing',
				__( 'Content Source & Navigation', 'mustuse-apps-pub' ),
				[ self::class, 'renderScreenRoutingMetaBox' ],
				Screen::POST_TYPE,
				'normal',
				'high'
			);
		} );

		add_action( 'save_post_' . Screen::POST_TYPE, [ self::class, 'saveScreenDeeplink' ], 10, 2 );
		add_action( 'save_post_' . Screen::POST_TYPE, [ self::class, 'saveScreenSlug' ], 10, 2 );
		add_action( 'save_post_' . Screen::POST_TYPE, [ self::class, 'saveScreenRouting' ], 10, 2 );

		// "Add New" on the Screen list screen must carry app_id.
		add_filter( 'post_new_file', static function ( $url, $post_type ) {
			if ( $post_type === Screen::POST_TYPE ) {
				$appId = isset( $_GET['app_id'] ) ? (int) $_GET['app_id'] : 0;
				if ( $appId ) {
					$url = add_query_arg( 'app_id', $appId, $url );
				}
			}
			return $url;
		}, 10, 2 );

		add_action( 'admin_enqueue_scripts', static function (): void {
			global $pagenow, $typenow;
			if ( $pagenow !== 'edit.php' ) {
				return;
			}
			if ( $typenow !== Screen::POST_TYPE ) {
				return;
			}
			if ( isset( $_GET['app_id'] ) ) {
				return;
			}
			wp_register_style( 'mua-editorial-inline', false, [], \defined( 'MUA_PUB_VERSION' ) ? MUA_PUB_VERSION : '0' );
			wp_enqueue_style( 'mua-editorial-inline' );
			wp_add_inline_style( 'mua-editorial-inline', '.page-title-action { display: none !important; }' );
		} );

		add_action( 'save_post', [ self::class, 'guardOrphanedChildren' ], 10, 2 );

		add_action( 'admin_notices', static function (): void {
			if ( isset( $_GET['mua_orphan_error'] ) ) {
				echo '<div class="notice notice-error"><p>';
				esc_html_e( 'You must create Screens from within an App. Orphaned content is not allowed.', 'mustuse-apps-pub' );
				echo '</p></div>';
			}

			$key    = 'mua_slug_lock_notice_' . get_current_user_id();
			$notice = get_transient( $key );
			if ( \is_array( $notice ) && ! empty( $notice['kept'] ) ) {
				delete_transient( $key );
				$kept     = (string) $notice['kept'];
				$isScreen = ( $notice['scope'] ?? '' ) === 'screen';
				$message  = $isScreen
					? \sprintf(
						/* translators: %s: kept screen slug */
						__( 'Screen slug locked to "%s" — the app has already shipped. Renaming would break manifest deeplinks for every install.', 'mustuse-apps-pub' ),
						$kept
					)
					: \sprintf(
						/* translators: %s: kept app slug */
						__( 'App slug locked to "%s" — already shipped to the app stores. Renaming would break every existing install.', 'mustuse-apps-pub' ),
						$kept
					);
				echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
			}
		} );
	}

	/**
	 * Flag a Screen save that has no owning app. We redirect with a
	 * query-arg rather than mutating the post — mutating inside save_post
	 * recurses, and the creation flow writes `_mua_app_id` atomically via
	 * meta_input so legitimate creates always have it on the first save.
	 */
	public static function guardOrphanedChildren( int $postId, \WP_Post $post ): void {
		if ( $post->post_type !== Screen::POST_TYPE ) {
			return;
		}
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}
		if ( $post->post_status === 'auto-draft' ) {
			return;
		}
		if ( (int) get_post_meta( $postId, '_mua_app_id', true ) > 0 ) {
			return;
		}

		add_filter( 'redirect_post_location', static function ( string $location ): string {
			return add_query_arg( 'mua_orphan_error', '1', $location );
		} );
	}

	public static function renderScreenAppMetaBox( \WP_Post $post ): void {
		$appId = (int) get_post_meta( $post->ID, '_mua_app_id', true );
		$app   = $appId ? App::find( $appId ) : NULL;

		if ( $app ) {
			$editUrl = (string) get_edit_post_link( $app->id(), 'raw' );
			\printf(
				'<p><a href="%s" class="button">&larr; %s</a></p>',
				esc_url( $editUrl ),
				esc_html( \sprintf( __( 'Back to %s', 'mustuse-apps-pub' ), $app->title() ) )
			);
		} else {
			echo '<p>' . esc_html__( 'This screen is not linked to an app.', 'mustuse-apps-pub' ) . '</p>';
		}
	}

	public static function renderScreenSlugMetaBox( \WP_Post $post ): void {
		wp_nonce_field( 'mua_save_screen_slug', '_mua_screen_slug_nonce' );

		// Read the canonical publisher-intended slug from meta, never
		// from `post_name`. `post_name` is an internal
		// `{app-slug}-{screen-slug}` namespaced value WP needs for its
		// global hierarchical uniqueness constraint — we keep it out of
		// the UI so the publisher sees the clean slug they typed.
		$screen = Screen::find( $post->ID );
		$slug   = $screen instanceof Screen ? $screen->slug() : (string) get_post_meta( $post->ID, '_mua_screen_slug', true );
		?>
		<p>
			<label for="mua-screen-slug" class="screen-reader-text">
				<?php esc_html_e( 'Screen slug', 'mustuse-apps-pub' ); ?>
			</label>
			<input type="text" id="mua-screen-slug" name="mua_screen_slug" class="widefat"
				value="<?php echo esc_attr( $slug ); ?>" placeholder="home" pattern="[a-z0-9-]+">
		</p>
		<p class="description">
			<?php esc_html_e( 'Lowercase letters, digits, and hyphens. Scoped to this app — two different apps can both use "home". Becomes the screen id the shell uses at runtime.', 'mustuse-apps-pub' ); ?>
		</p>
		<?php
	}

	public static function saveScreenSlug( int $postId, \WP_Post $post ): void {
		if ( $post->post_type !== Screen::POST_TYPE ) {
			return;
		}
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$nonce = isset( $_POST['_mua_screen_slug_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_mua_screen_slug_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'mua_save_screen_slug' ) ) {
			return;
		}

		if ( ! \array_key_exists( 'mua_screen_slug', $_POST ) ) {
			return;
		}

		$desired = sanitize_title( (string) $_POST['mua_screen_slug'] );
		if ( $desired === '' ) {
			return;
		}

		// Write only the canonical meta. `Screen::normaliseSlugWithinApp`
		// (priority 20 on `save_post_mua_app_screen`) runs right after
		// this handler and rebuilds `post_name` as `{app-slug}-{desired}`
		// via a direct DB write — it's the single authoritative writer
		// of `post_name` for screens.
		$current = (string) get_post_meta( $postId, '_mua_screen_slug', true );
		if ( $desired !== $current ) {
			update_post_meta( $postId, '_mua_screen_slug', $desired );
		}
	}

	public static function renderScreenDeeplinkMetaBox( \WP_Post $post ): void {
		$current = (string) get_post_meta( $post->ID, '_mua_deeplink_path', true );
		wp_nonce_field( 'mua_save_screen_deeplink', '_mua_screen_deeplink_nonce' );
		?>
		<p>
			<label for="mua-screen-deeplink-path" class="screen-reader-text">
				<?php esc_html_e( 'Deep link path', 'mustuse-apps-pub' ); ?>
			</label>
			<input type="text" id="mua-screen-deeplink-path" name="mua_deeplink_path" class="widefat"
				value="<?php echo esc_attr( $current ); ?>" placeholder="/article/{id}">
		</p>
		<p class="description">
			<?php esc_html_e( 'Optional. Path template like /article/{id} or /category/{slug}. Use {name} placeholders; literals must be alphanumeric, ".", "-", or "_".', 'mustuse-apps-pub' ); ?>
		</p>
		<?php
	}

	public static function saveScreenDeeplink( int $postId, \WP_Post $post ): void {
		if ( $post->post_type !== Screen::POST_TYPE ) {
			return;
		}
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$nonce = isset( $_POST['_mua_screen_deeplink_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_mua_screen_deeplink_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'mua_save_screen_deeplink' ) ) {
			return;
		}

		if ( ! \array_key_exists( 'mua_deeplink_path', $_POST ) ) {
			return;
		}

		update_post_meta( $postId, '_mua_deeplink_path', (string) $_POST['mua_deeplink_path'] );
	}

	public static function renderScreenRoutingMetaBox( \WP_Post $post ): void {
		$screen = Screen::find( $post->ID );
		if ( ! $screen instanceof Screen ) {
			echo '<p>' . esc_html__( 'Save the screen first to configure routing.', 'mustuse-apps-pub' ) . '</p>';
			return;
		}

		wp_nonce_field( 'mua_save_screen_routing', '_mua_screen_routing_nonce' );

		$role             = $screen->role();
		$routeType        = $screen->routeType();
		$deeplinkPath     = (string) get_post_meta( $post->ID, '_mua_deeplink_path', true );
		$customRoutes     = ScreenRouteRegistry::all();
		$publicPostTypes  = self::choosablePostTypes();
		$publicTaxonomies = self::choosableTaxonomies();
		$termIdCsv        = \implode( ', ', $screen->routeTermIds() );
		$metaQuery        = $screen->routeMetaQuery();
		$orderby          = $screen->routeOrderby();
		$order            = $screen->routeOrder();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Screen role', 'mustuse-apps-pub' ); ?></th>
				<td>
					<select name="mua_screen_role" class="mua-screen-role" id="mua_screen_role">
						<option value="static" <?php selected( $role, 'static' ); ?>>
							<?php esc_html_e( 'Static — just renders its blocks', 'mustuse-apps-pub' ); ?>
						</option>
						<option value="archive" <?php selected( $role, 'archive' ); ?>>
							<?php esc_html_e( 'Archive — renders a post/term query', 'mustuse-apps-pub' ); ?>
						</option>
						<option value="detail" <?php selected( $role, 'detail' ); ?>>
							<?php esc_html_e( 'Detail — renders one post/term via URL pattern', 'mustuse-apps-pub' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Static screens are landing pages (home, about). Archive screens render a list (category index). Detail screens render a single post and are reached via a URL pattern like /article/{slug}.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
			<tr class="mua-screen-role-fields mua-screen-role-fields--detail" <?php echo $role === 'detail' ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'URL pattern', 'mustuse-apps-pub' ); ?></th>
				<td>
					<input type="text" name="mua_deeplink_path_inline" class="widefat"
						value="<?php echo esc_attr( $deeplinkPath ); ?>" placeholder="/article/{slug}">
					<p class="description">
						<?php esc_html_e( 'Use {slug} or {id} placeholders. Required for detail screens.', 'mustuse-apps-pub' ); ?>
						<?php if ( $role === 'detail' && $deeplinkPath === '' ) : ?>
							<br><strong style="color:#b0264c;">
								<?php esc_html_e( 'Required — Ship It blocks until this pattern is set.', 'mustuse-apps-pub' ); ?>
							</strong>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr class="mua-screen-role-fields mua-screen-role-fields--archive" <?php echo $role === 'archive' ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'Content source', 'mustuse-apps-pub' ); ?></th>
				<td>
					<select name="mua_route_type" class="mua-screen-route-type" id="mua_route_type">
						<option value="none" <?php selected( $routeType, 'none' ); ?>>
							<?php esc_html_e( 'None (static blocks only)', 'mustuse-apps-pub' ); ?>
						</option>
						<option value="standard" <?php selected( $routeType, 'standard' ); ?>>
							<?php esc_html_e( 'Standard (post type + term)', 'mustuse-apps-pub' ); ?>
						</option>
						<option value="custom" <?php selected( $routeType, 'custom' ); ?>>
							<?php esc_html_e( 'Custom (registered PHP provider)', 'mustuse-apps-pub' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Determines what content the shell fetches when this archive is opened.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
			<tr class="mua-screen-route-fields mua-screen-route-fields--standard" <?php echo $routeType === 'standard' ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'Post type', 'mustuse-apps-pub' ); ?></th>
				<td>
					<select name="mua_route_post_type">
						<option value=""><?php esc_html_e( '— Select post type —', 'mustuse-apps-pub' ); ?></option>
						<?php foreach ( $publicPostTypes as $pt ) : ?>
							<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $screen->routePostType(), $pt->name ); ?>>
								<?php echo esc_html( $pt->label ); ?> (<?php echo esc_html( $pt->name ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr class="mua-screen-route-fields mua-screen-route-fields--standard" <?php echo $routeType === 'standard' ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'Taxonomy (optional)', 'mustuse-apps-pub' ); ?></th>
				<td>
					<select name="mua_route_taxonomy">
						<option value=""><?php esc_html_e( '— None —', 'mustuse-apps-pub' ); ?></option>
						<?php foreach ( $publicTaxonomies as $tx ) : ?>
							<option value="<?php echo esc_attr( $tx->name ); ?>" <?php selected( $screen->routeTaxonomy(), $tx->name ); ?>>
								<?php echo esc_html( $tx->label ); ?> (<?php echo esc_html( $tx->name ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="mua_route_term_ids" class="widefat"
						value="<?php echo esc_attr( $termIdCsv ); ?>"
						placeholder="<?php esc_attr_e( 'Term IDs (comma-separated)', 'mustuse-apps-pub' ); ?>"
						style="margin-top:6px;">
					<p class="description">
						<?php esc_html_e( 'Multiple term IDs combine with OR (posts in any listed term).', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
			<tr class="mua-screen-route-fields mua-screen-route-fields--standard" <?php echo $routeType === 'standard' ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'Order', 'mustuse-apps-pub' ); ?></th>
				<td>
					<select name="mua_route_orderby">
						<?php foreach ( [ 'date' => 'Date', 'title' => 'Title', 'menu_order' => 'Menu order', 'modified' => 'Modified', 'comment_count' => 'Comment count', 'rand' => 'Random' ] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $orderby, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="mua_route_order" style="margin-left:8px;">
						<option value="DESC" <?php selected( $order, 'DESC' ); ?>><?php esc_html_e( 'Descending', 'mustuse-apps-pub' ); ?></option>
						<option value="ASC"  <?php selected( $order, 'ASC' ); ?>><?php esc_html_e( 'Ascending', 'mustuse-apps-pub' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="mua-screen-route-fields mua-screen-route-fields--standard" <?php echo $routeType === 'standard' ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'Meta query (optional)', 'mustuse-apps-pub' ); ?></th>
				<td>
					<?php $rows = $metaQuery !== [] ? $metaQuery : [ [ 'key' => '', 'value' => '', 'compare' => '=' ] ]; ?>
					<div class="mua-meta-query-rows">
						<?php foreach ( $rows as $i => $clause ) : ?>
							<p>
								<input type="text" name="mua_route_meta_query[<?php echo (int) $i; ?>][key]"
									value="<?php echo esc_attr( (string) ( $clause['key'] ?? '' ) ); ?>"
									placeholder="meta_key" style="width:220px;">
								<select name="mua_route_meta_query[<?php echo (int) $i; ?>][compare]">
									<?php foreach ( [ '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' ] as $op ) : ?>
										<option value="<?php echo esc_attr( $op ); ?>" <?php selected( (string) ( $clause['compare'] ?? '=' ), $op ); ?>><?php echo esc_html( $op ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="text" name="mua_route_meta_query[<?php echo (int) $i; ?>][value]"
									value="<?php echo esc_attr( (string) ( $clause['value'] ?? '' ) ); ?>"
									placeholder="value" style="width:220px;">
							</p>
						<?php endforeach; ?>
					</div>
					<p class="description">
						<?php esc_html_e( 'Filter the archive by registered meta fields. For IN/NOT IN, separate values with commas. Capped at 10 clauses.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
			<tr class="mua-screen-route-fields mua-screen-route-fields--custom" <?php echo $routeType === 'custom' ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'Registered provider', 'mustuse-apps-pub' ); ?></th>
				<td>
					<select name="mua_route_custom_id">
						<option value=""><?php esc_html_e( '— Select provider —', 'mustuse-apps-pub' ); ?></option>
						<?php foreach ( $customRoutes as $route ) : ?>
							<option value="<?php echo esc_attr( (string) $route['id'] ); ?>" <?php selected( $screen->routeCustomId(), $route['id'] ); ?>>
								<?php echo esc_html( (string) $route['label'] ); ?>
							</option>
						<?php endforeach; ?>
						<?php if ( $screen->routeCustomId() !== '' && ! isset( $customRoutes[ $screen->routeCustomId()] ) ) : ?>
							<option value="<?php echo esc_attr( $screen->routeCustomId() ); ?>" selected>
								<?php echo esc_html( $screen->routeCustomId() ); ?>
								(<?php esc_html_e( 'not registered', 'mustuse-apps-pub' ); ?>)
							</option>
						<?php endif; ?>
					</select>
					<p class="description">
						<?php
						if ( empty( $customRoutes ) ) {
							esc_html_e( 'No custom providers registered. Use register_screen_route() in a plugin or theme.', 'mustuse-apps-pub' );
						} else {
							esc_html_e( 'Pick a provider registered via register_screen_route().', 'mustuse-apps-pub' );
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show in bottom nav', 'mustuse-apps-pub' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="mua_show_in_nav" value="1" <?php checked( $screen->showInNav() ); ?>>
						<?php esc_html_e( 'Include this screen in the app\'s bottom tab bar (max 5)', 'mustuse-apps-pub' ); ?>
					</label>
					<br>
					<label style="display:inline-block;margin-top:6px;">
						<?php esc_html_e( 'Nav order:', 'mustuse-apps-pub' ); ?>
						<input type="number" min="0" max="100" name="mua_nav_order"
							value="<?php echo esc_attr( (string) $screen->navOrder() ); ?>" style="width:80px;">
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show in side nav', 'mustuse-apps-pub' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="mua_show_in_side_nav" value="1" <?php checked( $screen->showInSideNav() ); ?>>
						<?php esc_html_e( 'Include this screen in the app\'s curated side menu (no cap)', 'mustuse-apps-pub' ); ?>
					</label>
					<br>
					<label style="display:inline-block;margin-top:6px;">
						<?php esc_html_e( 'Side nav order:', 'mustuse-apps-pub' ); ?>
						<input type="number" min="0" max="100" name="mua_side_nav_order"
							value="<?php echo esc_attr( (string) $screen->sideNavOrder() ); ?>" style="width:80px;">
					</label>
					<p class="description">
						<?php esc_html_e( 'When empty, the side menu falls back to the auto-generated drawer (every published screen). Curated side-nav wins when populated.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Use as home', 'mustuse-apps-pub' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="mua_is_home" value="1" <?php checked( $screen->isHome() ); ?>>
						<?php esc_html_e( 'Route `/` to this screen. Only one screen per app can be the home.', 'mustuse-apps-pub' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fallback role', 'mustuse-apps-pub' ); ?></th>
				<td>
					<?php $fallbackFor = $screen->fallbackFor(); ?>
					<select name="mua_is_fallback_for">
						<?php foreach ( [
							'none'              => 'Not a fallback',
							'post'              => 'Generic post detail (any CPT)',
							'page'              => 'Generic page detail',
							'archive_category'  => 'Generic category archive',
							'archive_tag'       => 'Generic tag archive',
							'archive_taxonomy'  => 'Generic taxonomy archive',
							'author'            => 'Generic author profile',
							'search'            => 'Generic search results',
							'any'               => '404 — catch-all fallback',
						] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $fallbackFor, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'When a deep link resolves to content that has no specifically-targeted screen, the shell renders this screen as a fallback. Pick `any` for your 404 / catch-all. WordPress-style template hierarchy.', 'mustuse-apps-pub' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function saveScreenRouting( int $postId, \WP_Post $post ): void {
		if ( $post->post_type !== Screen::POST_TYPE ) {
			return;
		}
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$nonce = isset( $_POST['_mua_screen_routing_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_mua_screen_routing_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'mua_save_screen_routing' ) ) {
			return;
		}

		// The Role picker's inline deeplink_path input writes to the same
		// `_mua_deeplink_path` meta the Deeplink metabox below also writes,
		// so either entry point stays in sync.
		$role = \is_string( $_POST['mua_screen_role'] ?? NULL ) ? (string) $_POST['mua_screen_role'] : 'static';
		if ( ! \in_array( $role, [ 'static', 'archive', 'detail' ], true ) ) {
			$role = 'static';
		}
		update_post_meta( $postId, '_mua_screen_role', $role );

		if ( $role === 'detail' && \array_key_exists( 'mua_deeplink_path_inline', $_POST ) ) {
			update_post_meta( $postId, '_mua_deeplink_path', (string) $_POST['mua_deeplink_path_inline'] );
		}

		$routeType = \is_string( $_POST['mua_route_type'] ?? NULL ) ? (string) $_POST['mua_route_type'] : 'none';
		if ( ! \in_array( $routeType, [ 'none', 'standard', 'custom' ], true ) ) {
			$routeType = 'none';
		}
		// Non-archive screens don't emit a routing row in the manifest;
		// force route_type=none so stale archive config can't leak in.
		if ( $role !== 'archive' ) {
			$routeType = 'none';
		}
		update_post_meta( $postId, '_mua_route_type', $routeType );

		if ( $routeType === 'standard' ) {
			$postType = sanitize_key( (string) ( $_POST['mua_route_post_type'] ?? '' ) );
			update_post_meta( $postId, '_mua_route_post_type', $postType );
			update_post_meta( $postId, '_mua_route_taxonomy',  sanitize_key( (string) ( $_POST['mua_route_taxonomy'] ?? '' ) ) );

			$termCsv = (string) ( $_POST['mua_route_term_ids'] ?? '' );
			$termIds = \array_values( \array_unique( \array_filter( \array_map(
				static fn( string $s ): int => (int) \trim( $s ),
				$termCsv === '' ? [] : \explode( ',', $termCsv )
			) ) ) );
			update_post_meta( $postId, '_mua_route_term_ids', $termIds );

			$rawMq = $_POST['mua_route_meta_query'] ?? [];
			$clauses = \is_array( $rawMq )
				? Screen::sanitiseMetaQuery( \array_values( \array_filter( $rawMq, static fn( $c ): bool => \is_array( $c ) && ! empty( \trim( (string) ( $c['key'] ?? '' ) ) ) ) ) )
				: [];
			update_post_meta( $postId, '_mua_route_meta_query', $clauses );

			$orderby = \is_string( $_POST['mua_route_orderby'] ?? null ) ? (string) $_POST['mua_route_orderby'] : 'date';
			$order   = \is_string( $_POST['mua_route_order']   ?? null ) ? \strtoupper( (string) $_POST['mua_route_order'] ) : 'DESC';
			update_post_meta( $postId, '_mua_route_orderby', $orderby );
			update_post_meta( $postId, '_mua_route_order',   $order === 'ASC' ? 'ASC' : 'DESC' );

			update_post_meta( $postId, '_mua_route_custom_id', '' );

			// Auto-suggest a detail-screen deeplink for newly-wired CPT
			// archives that also declare a detail pattern elsewhere —
			// `/{post_type}/{slug}` is the conventional shape.
			if ( $postType !== '' && $role === 'detail' && (string) get_post_meta( $postId, '_mua_deeplink_path', true ) === '' ) {
				update_post_meta( $postId, '_mua_deeplink_path', '/' . $postType . '/{slug}' );
			}
		} elseif ( $routeType === 'custom' ) {
			$customId      = sanitize_key( (string) ( $_POST['mua_route_custom_id'] ?? '' ) );
			$registeredIds = \array_keys( ScreenRouteRegistry::all() );
			if ( $customId !== '' && ! \in_array( $customId, $registeredIds, true ) ) {
				$customId = '';
			}
			update_post_meta( $postId, '_mua_route_custom_id', $customId );
			update_post_meta( $postId, '_mua_route_post_type', '' );
			update_post_meta( $postId, '_mua_route_taxonomy',  '' );
			update_post_meta( $postId, '_mua_route_term_ids',  [] );
			update_post_meta( $postId, '_mua_route_meta_query', [] );
		} else {
			update_post_meta( $postId, '_mua_route_post_type', '' );
			update_post_meta( $postId, '_mua_route_taxonomy',  '' );
			update_post_meta( $postId, '_mua_route_term_ids',  [] );
			update_post_meta( $postId, '_mua_route_meta_query', [] );
			update_post_meta( $postId, '_mua_route_custom_id', '' );
		}

		update_post_meta( $postId, '_mua_show_in_nav', ! empty( $_POST['mua_show_in_nav'] ) );
		update_post_meta( $postId, '_mua_nav_order', (int) ( $_POST['mua_nav_order'] ?? 0 ) );
		update_post_meta( $postId, '_mua_show_in_side_nav', ! empty( $_POST['mua_show_in_side_nav'] ) );
		update_post_meta( $postId, '_mua_side_nav_order', (int) ( $_POST['mua_side_nav_order'] ?? 0 ) );

		$isHome = ! empty( $_POST['mua_is_home'] );
		update_post_meta( $postId, '_mua_is_home', $isHome );
		if ( $isHome ) {
			self::clearHomeFlagForSiblings( $postId );
		}

		$fallbackFor = \is_string( $_POST['mua_is_fallback_for'] ?? null ) ? (string) $_POST['mua_is_fallback_for'] : 'none';
		$allowed     = [ 'none', 'post', 'page', 'archive_category', 'archive_tag', 'archive_taxonomy', 'author', 'search', 'any' ];
		if ( ! \in_array( $fallbackFor, $allowed, true ) ) {
			$fallbackFor = 'none';
		}
		update_post_meta( $postId, '_mua_is_fallback_for', $fallbackFor );
	}

	/**
	 * Public-queryable post types available in the Screen routing UI.
	 * Drops internal plugin-owned types (attachment, revision, nav_menu_item,
	 * plus our own `mua_*`) and lets extensions opt in/out via filter.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function choosablePostTypes(): array {
		$types = get_post_types( [ 'show_in_rest' => true ], 'objects' );
		$blocklist = [ 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'mua_app', 'mua_app_screen' ];
		$filtered = [];
		foreach ( $types as $name => $object ) {
			if ( \in_array( $name, $blocklist, true ) ) {
				continue;
			}
			$filtered[ $name ] = $object;
		}
		/** @see mua_admin_post_type_choices filter — extensions opt types in/out of the admin UI. */
		return (array) apply_filters( 'mua_admin_post_type_choices', $filtered );
	}

	/**
	 * Public-queryable taxonomies available in the Screen routing UI.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function choosableTaxonomies(): array {
		$taxes = get_taxonomies( [ 'show_in_rest' => true ], 'objects' );
		$blocklist = [ 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' ];
		$filtered = [];
		foreach ( $taxes as $name => $object ) {
			if ( \in_array( $name, $blocklist, true ) ) {
				continue;
			}
			$filtered[ $name ] = $object;
		}
		/** @see mua_admin_taxonomy_choices filter — extensions opt taxonomies in/out of the admin UI. */
		return (array) apply_filters( 'mua_admin_taxonomy_choices', $filtered );
	}

	private static function clearHomeFlagForSiblings( int $homeScreenId ): void {
		$appId = (int) get_post_meta( $homeScreenId, '_mua_app_id', true );
		if ( $appId <= 0 ) {
			return;
		}
		$siblings = Screen::findByApp( $appId );
		foreach ( $siblings as $sibling ) {
			if ( $sibling->id() !== $homeScreenId && $sibling->isHome() ) {
				update_post_meta( $sibling->id(), '_mua_is_home', false );
			}
		}
	}

}
