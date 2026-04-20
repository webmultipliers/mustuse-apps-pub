<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use WP_Post;

/**
 * Per-app access control.
 *
 * Each App can carry an explicit list of WordPress user IDs in
 * `_mua_app_owners`. When set, only those users (and site admins via
 * `manage_options`) may edit, delete, or ship the App and its child
 * Screens. When the list is empty the existing back-compat behaviour
 * applies — anyone with the underlying primitive cap can act.
 *
 * The Owners metabox is admin-only; non-admins see a read-only summary.
 */
final class AppPermissions
{
    private const META_KEY      = '_mua_app_owners';
    private const NONCE_FIELD   = '_mua_app_owners_nonce';
    private const NONCE_ACTION  = 'mua_save_app_owners';
    private const GATED_CAPS    = [ 'edit_post', 'delete_post', 'publish_post', 'read_post', 'mua_ship_app' ];

    public static function register(): void
    {
        add_filter('map_meta_cap', [ self::class, 'mapMetaCap' ], 10, 4);
        add_action('add_meta_boxes_' . App::POST_TYPE, [ self::class, 'registerMetabox' ]);
        add_action('save_post_' . App::POST_TYPE, [ self::class, 'save' ], 10, 1);
    }

    /**
     * @param string[]   $caps
     * @param mixed[]    $args
     * @return string[]
     */
    public static function mapMetaCap(array $caps, string $cap, int $userId, array $args): array
    {
        if (! \in_array($cap, self::GATED_CAPS, true)) {
            return $caps;
        }

        $appId = self::resolveAppId($cap, $args);
        if ($appId === 0) {
            return $caps;
        }

        if (\function_exists('user_can') && user_can($userId, 'manage_options')) {
            return $caps;
        }

        $owners = self::ownersOf($appId);
        if ($owners === []) {
            return $caps;
        }

        if (! \in_array($userId, $owners, true)) {
            return [ 'do_not_allow' ];
        }
        return $caps;
    }

    /**
     * @param mixed[] $args
     */
    private static function resolveAppId(string $cap, array $args): int
    {
        if ($cap === 'mua_ship_app') {
            return (int) ($args[0] ?? 0);
        }
        $postId = (int) ($args[0] ?? 0);
        if ($postId === 0) {
            return 0;
        }
        $postType = \function_exists('get_post_type') ? get_post_type($postId) : '';
        if ($postType === App::POST_TYPE) {
            return $postId;
        }
        if ($postType === Screen::POST_TYPE) {
            return (int) get_post_meta($postId, '_mua_app_id', true);
        }
        return 0;
    }

    /**
     * @return int[]
     */
    public static function ownersOf(int $appId): array
    {
        $owners = get_post_meta($appId, self::META_KEY, true);
        if (! \is_array($owners)) {
            return [];
        }
        return \array_values(\array_unique(\array_filter(\array_map('intval', $owners))));
    }

    public static function registerMetabox(): void
    {
        add_meta_box(
            'mua_app_owners',
            __('Owners', 'mustuse-apps-pub'),
            [ self::class, 'renderMetabox' ],
            App::POST_TYPE,
            'side',
            'low'
        );
    }

    public static function renderMetabox(WP_Post $post): void
    {
        $owners      = self::ownersOf($post->ID);
        $isAdmin     = current_user_can('manage_options');
        $loginLookup = static function (int $id): string {
            $user = get_userdata($id);
            return $user ? $user->user_login : (string) $id;
        };

        if (! $isAdmin) {
            if ($owners === []) {
                echo '<p>' . esc_html__('No owners configured. Anyone with edit access can manage this app.', 'mustuse-apps-pub') . '</p>';
                return;
            }
            $names = \array_map($loginLookup, $owners);
            echo '<p>' . esc_html__('Owners:', 'mustuse-apps-pub') . '</p><ul style="margin:0;padding-left:18px;">';
            foreach ($names as $name) {
                echo '<li>' . esc_html((string) $name) . '</li>';
            }
            echo '</ul>';
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        $current = $owners === []
            ? ''
            : \implode("\n", \array_map($loginLookup, $owners));
        ?>
        <p>
            <label for="mua-app-owners">
                <?php esc_html_e('User IDs or logins (one per line):', 'mustuse-apps-pub'); ?>
            </label>
            <textarea id="mua-app-owners" name="mua_app_owners" rows="4" class="widefat code"><?php echo esc_textarea($current); ?></textarea>
        </p>
        <p class="description">
            <?php esc_html_e('Leave empty to keep default behaviour: any user with edit access can manage this app. When populated, only listed users (plus site admins) can edit, delete, or ship.', 'mustuse-apps-pub'); ?>
        </p>
        <?php
    }

    public static function save(int $postId): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $nonce = isset($_POST[ self::NONCE_FIELD ])
            ? sanitize_text_field(wp_unslash($_POST[ self::NONCE_FIELD ]))
            : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }
        if (! \array_key_exists('mua_app_owners', $_POST)) {
            return;
        }

        $raw   = (string) wp_unslash($_POST['mua_app_owners']);
        $lines = \preg_split('/[\r\n,]+/', $raw) ?: [];
        $ids   = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            if (\ctype_digit($line)) {
                $ids[] = (int) $line;
                continue;
            }
            $user = get_user_by('login', $line);
            if ($user) {
                $ids[] = (int) $user->ID;
            }
        }
        $ids = \array_values(\array_unique(\array_filter($ids)));

        if ($ids === []) {
            delete_post_meta($postId, self::META_KEY);
        } else {
            update_post_meta($postId, self::META_KEY, $ids);
        }
    }
}
