<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin;

use MustUse\Pub\Blocks\KitchenSinkPatterns;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Manifest\CapabilityRegistry;

/**
 * Kitchen-sink demo app scaffold — multi-screen capability tour.
 *
 * Each native capability cluster lives on its own screen so publishers
 * experience one feature at a time instead of a wall of buttons. The
 * home screen is a tour index of `browser_inapp`-style nav cards that
 * route into each capability page.
 *
 * Idempotent: the seeded app's slug is locked to `demo-kitchen-sink`
 * and every screen carries `_mua_seeded_by = 'kitchen-sink'` so the
 * reset action can delete the set cleanly.
 *
 * Hooks:
 *   admin_post_mua_create_demo_app → create()
 *   admin_post_mua_reset_kitchen_sink → reset()
 */
final class KitchenSinkScaffold
{
    public const ACTION        = 'mua_create_demo_app';
    public const RESET_ACTION  = 'mua_reset_kitchen_sink';
    public const NONCE         = '_mua_create_demo_app_nonce';
    public const RESET_NONCE   = '_mua_reset_kitchen_sink_nonce';
    public const SEED_FLAG     = 'kitchen-sink';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION,       [ self::class, 'handle' ]);
        add_action('admin_post_' . self::RESET_ACTION, [ self::class, 'handleReset' ]);
        add_action('admin_notices',                    [ self::class, 'renderCtaButton' ]);
    }

    public static function ctaUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::ACTION),
            self::ACTION,
            self::NONCE
        );
    }

    public static function resetUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::RESET_ACTION),
            self::RESET_ACTION,
            self::RESET_NONCE
        );
    }

    public static function renderCtaButton(): void
    {
        global $pagenow, $typenow;
        if ($pagenow !== 'edit.php' || $typenow !== App::POST_TYPE) {
            return;
        }
        if (! current_user_can('manage_options')) {
            return;
        }

        $counts = wp_count_posts(App::POST_TYPE);
        $total  = 0;
        foreach ([ 'publish', 'draft', 'pending', 'private', 'future' ] as $status) {
            $value  = \is_object($counts) && isset($counts->{$status}) ? $counts->{$status} : 0;
            $total += \is_numeric($value) ? (int) $value : 0;
        }
        if ($total > 0) {
            return;
        }

        ?>
        <div class="notice notice-info" style="padding:14px 22px;">
            <h3 style="margin:0 0 6px 0;"><?php esc_html_e('…or spin up a kitchen-sink demo', 'mustuse-apps-pub'); ?></h3>
            <p style="margin:0 0 10px 0; max-width:60ch;">
                <?php esc_html_e('Ten screens: a tour index plus a self-contained demo page for each native capability the plugin surfaces (capture, location, audio, sensors, dialogs, browser, auth, device, push).', 'mustuse-apps-pub'); ?>
            </p>
            <a href="<?php echo esc_url(self::ctaUrl()); ?>" class="button button-secondary">
                <?php esc_html_e('Create kitchen-sink app', 'mustuse-apps-pub'); ?>
            </a>
        </div>
        <?php
    }

    public static function renderSettingsCard(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $existing = self::findExistingApp();
        ?>
        <div class="mua-card" style="margin-top: var(--mua-space-lg);">
            <h2 class="mua-section-heading"><?php esc_html_e('Kitchen-sink demo', 'mustuse-apps-pub'); ?></h2>
            <p class="mua-text-muted">
                <?php esc_html_e('A ten-screen tour of every native capability the plugin exposes. Each capability has its own screen with a live demo and inline result display — useful for exploring behaviour or verifying parity on a connected shell.', 'mustuse-apps-pub'); ?>
            </p>
            <p>
                <?php if ($existing instanceof App) : ?>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $existing->id() . '&action=edit')); ?>" class="mua-button mua-button--outline">
                        <?php esc_html_e('Open existing kitchen-sink app', 'mustuse-apps-pub'); ?>
                    </a>
                    <a href="<?php echo esc_url(self::resetUrl()); ?>" class="mua-button mua-button--outline"
                       onclick="return confirm('<?php echo esc_js(__('Delete the kitchen-sink demo app and every seeded screen? This cannot be undone.', 'mustuse-apps-pub')); ?>')"
                       style="margin-left:8px;">
                        <?php esc_html_e('Reset kitchen-sink', 'mustuse-apps-pub'); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(self::ctaUrl()); ?>" class="mua-button mua-button--outline">
                        <?php esc_html_e('Create kitchen-sink app', 'mustuse-apps-pub'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    public static function handle(): void
    {
        self::guardNonce(self::ACTION, self::NONCE);

        // Idempotent: hand back to the existing seeded app.
        $existing = self::findExistingApp();
        if ($existing instanceof App) {
            wp_safe_redirect(admin_url('post.php?post=' . $existing->id() . '&action=edit'));
            exit;
        }

        $appId = wp_insert_post([
            'post_type'   => App::POST_TYPE,
            'post_status' => 'draft',
            'post_title'  => __('Kitchen Sink', 'mustuse-apps-pub'),
            'post_name'   => 'demo-kitchen-sink',
            'meta_input'  => [
                '_mua_app_types'        => [ 'mobile_ios', 'mobile_android' ],
                '_mua_app_version_name' => '1.0.0',
                '_mua_app_version_code' => 1,
                '_mua_fallback_policy'  => 'screen_404',
                '_mua_seeded_by'        => self::SEED_FLAG,
            ],
        ], true);
        if (is_wp_error($appId) || ! $appId) {
            wp_die(esc_html__('Could not create the kitchen-sink app.', 'mustuse-apps-pub'));
        }
        $app = App::find((int) $appId);
        if ($app === null) {
            wp_die(esc_html__('App created but not retrievable.', 'mustuse-apps-pub'));
        }

        self::seedAdvertisement($app);

        $firstScreenId = 0;
        foreach (self::screenDefinitions() as $def) {
            $screenId = wp_insert_post([
                'post_type'    => Screen::POST_TYPE,
                'post_status'  => 'publish',
                'post_title'   => $def['title'],
                'post_content' => $def['content'],
                'menu_order'   => $def['menu'],
                'meta_input'   => \array_merge(
                    [
                        '_mua_app_id'      => (int) $appId,
                        '_mua_screen_slug' => $def['slug'],
                        '_mua_seeded_by'   => self::SEED_FLAG,
                    ],
                    $def['meta']
                ),
            ], true);
            if (is_wp_error($screenId) || ! $screenId) {
                continue;
            }
            if ($firstScreenId === 0) {
                $firstScreenId = (int) $screenId;
            }
        }

        wp_safe_redirect(admin_url('post.php?post=' . $firstScreenId . '&action=edit'));
        exit;
    }

    public static function handleReset(): void
    {
        self::guardNonce(self::RESET_ACTION, self::RESET_NONCE);

        $app = self::findExistingApp();
        if ($app instanceof App) {
            // Child screens first, then the app itself. PostLifecycle
            // would cascade on trash, but force-delete is cleaner for a
            // reset — we want the ids gone.
            $screens = Screen::findByApp($app->id(), [ 'post_status' => 'any', 'numberposts' => -1 ]);
            foreach ($screens as $screen) {
                wp_delete_post($screen->id(), true);
            }
            wp_delete_post($app->id(), true);
        }

        wp_safe_redirect(admin_url('edit.php?post_type=' . App::POST_TYPE . '&mua_reset=1'));
        exit;
    }

    /**
     * Screen definitions, in menu order. Keyed by slug so the scaffold
     * can look up each one; rendered straight through to wp_insert_post.
     *
     * @return list<array{slug: string, title: string, menu: int, content: string, meta: array<string, mixed>}>
     */
    private static function screenDefinitions(): array
    {
        return [
            [
                'slug'    => 'home',
                'title'   => __('Home', 'mustuse-apps-pub'),
                'menu'    => 0,
                'content' => KitchenSinkPatterns::home(),
                'meta'    => [
                    '_mua_is_home'     => true,
                    '_mua_show_in_nav' => true,
                    '_mua_nav_order'   => 0,
                    '_mua_screen_role' => 'static',
                ],
            ],
            [
                'slug'    => 'capture',
                'title'   => __('Capture', 'mustuse-apps-pub'),
                'menu'    => 10,
                'content' => KitchenSinkPatterns::capture(),
                'meta'    => [
                    '_mua_show_in_nav' => true,
                    '_mua_nav_order'   => 1,
                    '_mua_screen_role' => 'static',
                ],
            ],
            [
                'slug'    => 'location',
                'title'   => __('Location', 'mustuse-apps-pub'),
                'menu'    => 20,
                'content' => KitchenSinkPatterns::location(),
                'meta'    => [
                    '_mua_show_in_nav' => true,
                    '_mua_nav_order'   => 2,
                    '_mua_screen_role' => 'static',
                ],
            ],
            [
                'slug'    => 'audio',
                'title'   => __('Audio', 'mustuse-apps-pub'),
                'menu'    => 30,
                'content' => KitchenSinkPatterns::audio(),
                'meta'    => [
                    '_mua_show_in_side_nav' => true,
                    '_mua_side_nav_order'   => 10,
                    '_mua_screen_role'      => 'static',
                ],
            ],
            [
                'slug'    => 'sensors',
                'title'   => __('Sensors', 'mustuse-apps-pub'),
                'menu'    => 40,
                'content' => KitchenSinkPatterns::sensors(),
                'meta'    => [
                    '_mua_show_in_side_nav' => true,
                    '_mua_side_nav_order'   => 20,
                    '_mua_screen_role'      => 'static',
                ],
            ],
            [
                'slug'    => 'dialogs',
                'title'   => __('Dialogs & share', 'mustuse-apps-pub'),
                'menu'    => 50,
                'content' => KitchenSinkPatterns::dialogs(),
                'meta'    => [
                    '_mua_show_in_side_nav' => true,
                    '_mua_side_nav_order'   => 30,
                    '_mua_screen_role'      => 'static',
                ],
            ],
            [
                'slug'    => 'browser',
                'title'   => __('Browser', 'mustuse-apps-pub'),
                'menu'    => 60,
                'content' => KitchenSinkPatterns::browser(),
                'meta'    => [
                    '_mua_show_in_side_nav' => true,
                    '_mua_side_nav_order'   => 40,
                    '_mua_screen_role'      => 'static',
                ],
            ],
            [
                'slug'    => 'auth',
                'title'   => __('Auth', 'mustuse-apps-pub'),
                'menu'    => 70,
                'content' => KitchenSinkPatterns::auth(),
                'meta'    => [
                    '_mua_show_in_nav'      => true,
                    '_mua_nav_order'        => 3,
                    '_mua_show_in_side_nav' => true,
                    '_mua_side_nav_order'   => 50,
                    '_mua_screen_role'      => 'static',
                ],
            ],
            [
                'slug'    => 'device',
                'title'   => __('Device & network', 'mustuse-apps-pub'),
                'menu'    => 80,
                'content' => KitchenSinkPatterns::device(),
                'meta'    => [
                    '_mua_show_in_nav'      => true,
                    '_mua_nav_order'        => 4,
                    '_mua_show_in_side_nav' => true,
                    '_mua_side_nav_order'   => 60,
                    '_mua_screen_role'      => 'static',
                ],
            ],
            [
                'slug'    => 'push',
                'title'   => __('Push', 'mustuse-apps-pub'),
                'menu'    => 90,
                'content' => KitchenSinkPatterns::push(),
                'meta'    => [
                    '_mua_show_in_side_nav' => true,
                    '_mua_side_nav_order'   => 70,
                    '_mua_screen_role'      => 'static',
                ],
            ],
            [
                'slug'    => '404',
                'title'   => __('Not found', 'mustuse-apps-pub'),
                'menu'    => 99,
                'content' => KitchenSinkPatterns::notFound(),
                'meta'    => [
                    '_mua_screen_role'     => 'static',
                    '_mua_is_fallback_for' => 'any',
                ],
            ],
        ];
    }

    /**
     * Find the scaffold-seeded app, if any. Identified via the
     * `_mua_seeded_by` meta so reset can't accidentally nuke an app the
     * publisher imported with the same slug.
     */
    private static function findExistingApp(): ?App
    {
        $candidates = get_posts([
            'post_type'   => App::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => '_mua_seeded_by', 'value' => self::SEED_FLAG, 'compare' => '=' ],
            ],
        ]);
        if (empty($candidates)) {
            return null;
        }
        return App::find((int) $candidates[0]);
    }

    private static function guardNonce(string $action, string $nonceKey): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'mustuse-apps-pub'));
        }
        $rawNonce = isset($_REQUEST[$nonceKey]) ? wp_unslash($_REQUEST[$nonceKey]) : '';
        $nonce    = \is_string($rawNonce) ? sanitize_text_field($rawNonce) : '';
        if (! wp_verify_nonce($nonce, $action)) {
            wp_die(esc_html__('Security check failed.', 'mustuse-apps-pub'));
        }
    }

    /**
     * Seed a fake capability advertisement so every demo block renders
     * without waiting on a real shell to connect back.
     */
    private static function seedAdvertisement(App $app): void
    {
        $native = [];
        foreach (\array_keys(CapabilityRegistry::MOBILE_CAPABILITIES) as $cap) {
            $native[$cap] = true;
        }
        CapabilityRegistry::recordAdvertisement($app, [
            'app_type'             => 'mobile_ios',
            'shell_version'        => 'kitchen-sink-scaffold',
            'native_capabilities'  => $native,
            'component_registry'   => [],
        ]);
    }
}
