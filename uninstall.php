<?php
/**
 * Uninstall handler for MustUse Apps — Publisher.
 *
 * Fires only when the plugin is deleted via the WP admin (not on
 * deactivate). Removes custom-table rows + plugin-owned options.
 *
 * Post data (mua_app, mua_app_screen) is NOT deleted by default because
 * losing it silently is worse than leaving it behind. Publishers who want
 * full removal can define `MUA_DELETE_POSTS_ON_UNINSTALL` in wp-config.php
 * before uninstalling.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Plugin-owned options (both autoloaded + non-autoloaded).
$options = [
    'mua_publisher_branding',
    'mua_manifest_signing_key',
    'mua_secure_storage_key',
    'mua_post_type_routing_index',
];

foreach ($options as $option) {
    delete_option($option);
}

// Orphaned transients — hit all mua_manifest_* and mua_content cache keys.
$transients = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_mua_%'
        OR option_name LIKE '_transient_timeout_mua_%'"
);
foreach ($transients as $name) {
    delete_option($name);
}

// Posts + per-post meta: only if opted in.
if (defined('MUA_DELETE_POSTS_ON_UNINSTALL') && MUA_DELETE_POSTS_ON_UNINSTALL) {
    foreach (['mua_app_screen', 'mua_app'] as $postType) {
        $postIds = get_posts([
            'post_type'   => $postType,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        foreach ($postIds as $postId) {
            wp_delete_post((int) $postId, true);
        }
    }
}

// Scheduled actions left in our group.
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('', [], 'mustuse-apps-pub');
}
