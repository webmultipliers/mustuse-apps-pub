<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin;

use MustUse\Pub\Admin\Seeders\ContentShowcaseSeeder;
use MustUse\Pub\Blocks\ContentShowcasePatterns;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Manifest\CapabilityRegistry;

/**
 * Content-showcase demo scaffold.
 *
 * One click:
 *   1. Runs `ContentShowcaseSeeder::seed()` — creates 5 categories, 8
 *      tags, 15 posts (with placeholder featured images), 2 pages,
 *      and a nav menu wired to them.
 *   2. Creates the 11 demo screens wired for routing (home, post
 *      detail, page detail, categories, category archive, tag
 *      archive, author, search, saved, about, 404).
 *   3. Seeds a full capability advertisement so native-action blocks
 *      on the demo render without a connected shell.
 *
 * Idempotent — every seeded item carries `_mua_seeded_by` meta, and
 * the scaffold refuses to duplicate an existing showcase app.
 *
 * Hooks:
 *   admin_post_mua_create_content_showcase       → handle()
 *   admin_post_mua_reset_content_showcase        → handleReset()
 */
final class ContentShowcaseScaffold
{
    public const ACTION       = 'mua_create_content_showcase';
    public const RESET_ACTION = 'mua_reset_content_showcase';
    public const NONCE        = '_mua_create_content_showcase_nonce';
    public const RESET_NONCE  = '_mua_reset_content_showcase_nonce';
    public const SEED_FLAG    = 'content-showcase';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION,       [ self::class, 'handle' ]);
        add_action('admin_post_' . self::RESET_ACTION, [ self::class, 'handleReset' ]);
        add_action('admin_notices',                    [ self::class, 'renderEmptyAppsCta' ]);
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

    public static function renderEmptyAppsCta(): void
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
            <h3 style="margin:0 0 6px 0;"><?php esc_html_e('…or mirror a full editorial site', 'mustuse-apps-pub'); ?></h3>
            <p style="margin:0 0 10px 0; max-width:60ch;">
                <?php esc_html_e('Seeds 15 demo posts (5 categories, 8 tags, placeholder images), 2 pages, and a nav menu — then builds 11 app screens wired to them. Click to see exactly what a real WordPress editorial site looks like on mobile.', 'mustuse-apps-pub'); ?>
            </p>
            <p style="margin:0 0 10px 0; color:#b0264c; font-size:12px;">
                <?php esc_html_e('Seeded posts publish to your site\'s public front-end until removed. Use the Reset button on the Settings page to wipe them cleanly.', 'mustuse-apps-pub'); ?>
            </p>
            <a href="<?php echo esc_url(self::ctaUrl()); ?>" class="button button-secondary">
                <?php esc_html_e('Create content-showcase app', 'mustuse-apps-pub'); ?>
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
        $isSeeded = ContentShowcaseSeeder::isSeeded();
        ?>
        <div class="mua-card" style="margin-top: var(--mua-space-lg);">
            <h2 class="mua-section-heading"><?php esc_html_e('Content showcase demo', 'mustuse-apps-pub'); ?></h2>
            <p class="mua-text-muted">
                <?php esc_html_e('A full editorial mobile app on top of seeded WordPress content: 15 posts across 5 categories, 8 tags, 2 pages, and a nav menu. Every block in the library renders with real content so publishers can evaluate against their own design intent.', 'mustuse-apps-pub'); ?>
            </p>
            <p class="mua-text-muted" style="color:#b0264c;">
                <?php esc_html_e('Seeded posts + pages publish to your site\'s public front-end. Reset below removes them cleanly.', 'mustuse-apps-pub'); ?>
            </p>
            <p>
                <?php if ($existing instanceof App) : ?>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $existing->id() . '&action=edit')); ?>" class="mua-button mua-button--outline">
                        <?php esc_html_e('Open existing content-showcase app', 'mustuse-apps-pub'); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(self::ctaUrl()); ?>" class="mua-button mua-button--outline">
                        <?php esc_html_e('Create content-showcase app', 'mustuse-apps-pub'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($existing instanceof App || $isSeeded) : ?>
                    <a href="<?php echo esc_url(self::resetUrl()); ?>" class="mua-button mua-button--outline"
                       onclick="return confirm('<?php echo esc_js(__('Delete the content-showcase app, every seeded screen, and every seeded post / page / category / tag / image? This cannot be undone.', 'mustuse-apps-pub')); ?>')"
                       style="margin-left:8px;">
                        <?php esc_html_e('Reset content showcase', 'mustuse-apps-pub'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    public static function handle(): void
    {
        self::guardNonce(self::ACTION, self::NONCE);

        $existing = self::findExistingApp();
        if ($existing instanceof App) {
            wp_safe_redirect(admin_url('post.php?post=' . $existing->id() . '&action=edit'));
            exit;
        }

        ContentShowcaseSeeder::seed();

        $appId = wp_insert_post([
            'post_type'   => App::POST_TYPE,
            'post_status' => 'draft',
            'post_title'  => __('Content Showcase', 'mustuse-apps-pub'),
            'post_name'   => 'demo-content-showcase',
            'meta_input'  => [
                '_mua_app_types'        => [ 'mobile_ios', 'mobile_android' ],
                '_mua_app_version_name' => '1.0.0',
                '_mua_app_version_code' => 1,
                '_mua_fallback_policy'  => 'web',
                '_mua_enable_comments'  => true,
                '_mua_seeded_by'        => self::SEED_FLAG,
            ],
        ], true);
        if (is_wp_error($appId) || ! $appId) {
            wp_die(esc_html__('Could not create the content-showcase app.', 'mustuse-apps-pub'));
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
            $screens = Screen::findByApp($app->id(), [ 'post_status' => 'any', 'numberposts' => -1 ]);
            foreach ($screens as $screen) {
                wp_delete_post($screen->id(), true);
            }
            wp_delete_post($app->id(), true);
        }

        ContentShowcaseSeeder::reset();

        wp_safe_redirect(admin_url('edit.php?post_type=' . App::POST_TYPE . '&mua_reset=content-showcase'));
        exit;
    }

    /**
     * @return list<array{slug: string, title: string, menu: int, content: string, meta: array<string, mixed>}>
     */
    private static function screenDefinitions(): array
    {
        return [
            [
                'slug'    => 'home',
                'title'   => __('Home', 'mustuse-apps-pub'),
                'menu'    => 0,
                'content' => ContentShowcasePatterns::home(),
                'meta'    => [
                    '_mua_is_home'     => true,
                    '_mua_show_in_nav' => true,
                    '_mua_nav_order'   => 0,
                    '_mua_screen_role' => 'static',
                ],
            ],
            [
                'slug'    => 'post',
                'title'   => __('Post detail', 'mustuse-apps-pub'),
                'menu'    => 10,
                'content' => ContentShowcasePatterns::postDetail(),
                'meta'    => [
                    '_mua_screen_role'      => 'detail',
                    '_mua_deeplink_path'    => '/post/{slug}',
                    '_mua_is_fallback_for'  => 'post',
                ],
            ],
            [
                'slug'    => 'page',
                'title'   => __('Page detail', 'mustuse-apps-pub'),
                'menu'    => 20,
                'content' => ContentShowcasePatterns::pageDetail(),
                'meta'    => [
                    '_mua_screen_role'      => 'detail',
                    '_mua_deeplink_path'    => '/page/{slug}',
                    '_mua_is_fallback_for'  => 'page',
                ],
            ],
            [
                'slug'    => 'categories',
                'title'   => __('Categories', 'mustuse-apps-pub'),
                'menu'    => 30,
                'content' => ContentShowcasePatterns::categoriesIndex(),
                'meta'    => [
                    '_mua_screen_role'  => 'static',
                    '_mua_show_in_nav'  => true,
                    '_mua_nav_order'    => 1,
                ],
            ],
            [
                'slug'    => 'category',
                'title'   => __('Category archive', 'mustuse-apps-pub'),
                'menu'    => 40,
                'content' => ContentShowcasePatterns::categoryDetail(),
                'meta'    => [
                    '_mua_screen_role'      => 'archive',
                    '_mua_route_type'       => 'standard',
                    '_mua_route_post_type'  => 'post',
                    '_mua_route_taxonomy'   => 'category',
                    '_mua_deeplink_path'    => '/category/{slug}',
                    '_mua_is_fallback_for'  => 'archive_category',
                ],
            ],
            [
                'slug'    => 'tag',
                'title'   => __('Tag archive', 'mustuse-apps-pub'),
                'menu'    => 50,
                'content' => ContentShowcasePatterns::tagDetail(),
                'meta'    => [
                    '_mua_screen_role'      => 'archive',
                    '_mua_route_type'       => 'standard',
                    '_mua_route_post_type'  => 'post',
                    '_mua_route_taxonomy'   => 'post_tag',
                    '_mua_deeplink_path'    => '/tag/{slug}',
                    '_mua_is_fallback_for'  => 'archive_tag',
                ],
            ],
            [
                'slug'    => 'author',
                'title'   => __('Author profile', 'mustuse-apps-pub'),
                'menu'    => 60,
                'content' => ContentShowcasePatterns::authorDetail(),
                'meta'    => [
                    '_mua_screen_role'      => 'detail',
                    '_mua_deeplink_path'    => '/author/{slug}',
                    '_mua_is_fallback_for'  => 'author',
                ],
            ],
            [
                'slug'    => 'search',
                'title'   => __('Search', 'mustuse-apps-pub'),
                'menu'    => 70,
                'content' => ContentShowcasePatterns::search(),
                'meta'    => [
                    '_mua_screen_role'      => 'static',
                    '_mua_show_in_nav'      => true,
                    '_mua_nav_order'        => 2,
                    '_mua_is_fallback_for'  => 'search',
                ],
            ],
            [
                'slug'    => 'saved',
                'title'   => __('Saved', 'mustuse-apps-pub'),
                'menu'    => 80,
                'content' => ContentShowcasePatterns::saved(),
                'meta'    => [
                    '_mua_screen_role'  => 'static',
                    '_mua_show_in_nav'  => true,
                    '_mua_nav_order'    => 3,
                ],
            ],
            [
                'slug'    => 'about',
                'title'   => __('About', 'mustuse-apps-pub'),
                'menu'    => 90,
                'content' => ContentShowcasePatterns::about(),
                'meta'    => [
                    '_mua_screen_role'       => 'static',
                    '_mua_show_in_side_nav'  => true,
                    '_mua_side_nav_order'    => 0,
                ],
            ],
            [
                'slug'    => '404',
                'title'   => __('Not found', 'mustuse-apps-pub'),
                'menu'    => 99,
                'content' => ContentShowcasePatterns::notFound(),
                'meta'    => [
                    '_mua_screen_role'      => 'static',
                    '_mua_is_fallback_for'  => 'any',
                ],
            ],
        ];
    }

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

    private static function seedAdvertisement(App $app): void
    {
        $native = [];
        foreach (\array_keys(CapabilityRegistry::MOBILE_CAPABILITIES) as $cap) {
            $native[$cap] = true;
        }
        CapabilityRegistry::recordAdvertisement($app, [
            'app_type'             => 'mobile_ios',
            'shell_version'        => 'content-showcase-scaffold',
            'native_capabilities'  => $native,
            'component_registry'   => [],
        ]);
    }
}
