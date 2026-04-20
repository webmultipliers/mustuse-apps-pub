<?php
/**
 * Plugin Name: MustUse Apps — Publisher
 * Plugin URI:  https://mustuse.com
 * Description: Turns your WordPress content into native mobile apps. Define apps, map content, compose push campaigns, and serve manifests to mobile shells.
 * Version:     0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author:      MustUse
 * Author URI:  https://mustuse.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mustuse-apps-pub
 * Domain Path: /languages
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'MUA_PUB_VERSION', '0.1.0' );
define( 'MUA_PUB_FILE', __FILE__ );
define( 'MUA_PUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'MUA_PUB_URL', plugin_dir_url( __FILE__ ) );

require_once MUA_PUB_DIR . 'vendor/autoload.php';

/**
 * Action Scheduler is a WP plugin distributed via Composer; it registers
 * global functions (`as_has_scheduled_action`, `as_schedule_single_action`)
 * from its own entry file rather than PSR-4 autoloading. Without this
 * require the Jobs layer fatals on plugin load.
 */
require_once MUA_PUB_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( MUA_PUB_FILE, [ MustUse\Pub\Data\SchemaManager::class, 'activate' ] );
register_deactivation_hook( MUA_PUB_FILE, [ MustUse\Pub\Data\SchemaManager::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ MustUse\Pub\Plugin::class, 'init' ] );

if ( ! function_exists( 'register_screen_route' ) ) {
	/**
	 * Register a custom screen route provider. The $provider callable
	 * receives the `App` model and must return a WP_Query-style args array.
	 *
	 * Call from theme/plugin bootstrap via the `mua_register_screen_routes`
	 * action:
	 *
	 *   add_action('mua_register_screen_routes', function () {
	 *       register_screen_route('latest-premium', 'Latest premium posts',
	 *           fn (MustUse\Pub\Data\Models\App $app) => [
	 *               'post_type' => 'post',
	 *               'meta_key'  => '_premium',
	 *           ]);
	 *   });
	 *
	 * The admin editor then lists "Latest premium posts" as a selectable
	 * custom route — without ever accepting a function name from user input.
	 */
	function register_screen_route( string $id, string $label, callable $provider ): void {
		MustUse\Pub\Routing\ScreenRouteRegistry::register( $id, $label, $provider );
	}
}
