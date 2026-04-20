<?php
/**
 * Canvas block: mustuse-apps-pub-canvas/branding-preview
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$appId = (int) ($b['postId'] ?? get_the_ID());
$branding = [];

if ($appId > 0
    && get_post_type($appId) === \MustUse\Pub\Data\Models\App::POST_TYPE
    && current_user_can('edit_post', $appId)) {
    $defaults  = get_option('mua_publisher_branding', []);
    $overrides = get_post_meta($appId, '_mua_branding', true);
    $branding  = wp_parse_args(
        is_array($overrides) ? $overrides : [],
        is_array($defaults) ? $defaults : []
    );
}

// Icon/logo/splash URLs live inside the merged `_mua_branding` array,
// not as standalone meta. Reading standalone keys always returned ''.
$iconUrl   = (string) ($branding['icon_url']   ?? '');
$logoUrl   = (string) ($branding['logo_url']   ?? '');
$splashUrl = (string) ($branding['splash_url'] ?? '');
$primary   = (string) ($branding['primary_color'] ?? ($branding['primaryColor'] ?? '#1a1a1a'));
$secondary = (string) ($branding['secondary_color'] ?? ($branding['secondaryColor'] ?? '#f5f5f5'));
?>
<div class="mua-canvas-block mua-canvas-block--branding-preview">
    <header class="mua-canvas-block__header">
        <h3 class="mua-canvas-block__title"><?php esc_html_e('Branding', 'mustuse-apps-pub'); ?></h3>
    </header>
    <div class="mua-canvas-block__body" style="display:flex; gap:16px; align-items:center;">
        <div style="width:64px; height:64px; border-radius:14px; background:<?php echo esc_attr($primary); ?>; display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <?php if ($iconUrl) : ?>
                <img src="<?php echo esc_url($iconUrl); ?>" alt="" style="width:100%; height:100%; object-fit:cover;" />
            <?php else : ?>
                <span style="color:#fff; font-size:11px;"><?php esc_html_e('icon', 'mustuse-apps-pub'); ?></span>
            <?php endif; ?>
        </div>
        <div>
            <p style="margin:0 0 4px;"><strong><?php esc_html_e('Icon:', 'mustuse-apps-pub'); ?></strong> <?php echo $iconUrl ? esc_html(basename(parse_url($iconUrl, PHP_URL_PATH) ?: '')) : esc_html__('not set', 'mustuse-apps-pub'); ?></p>
            <p style="margin:0 0 4px;"><strong><?php esc_html_e('Logo:', 'mustuse-apps-pub'); ?></strong> <?php echo $logoUrl ? esc_html(basename(parse_url($logoUrl, PHP_URL_PATH) ?: '')) : esc_html__('not set', 'mustuse-apps-pub'); ?></p>
            <p style="margin:0 0 4px;"><strong><?php esc_html_e('Splash:', 'mustuse-apps-pub'); ?></strong> <?php echo $splashUrl ? esc_html(basename(parse_url($splashUrl, PHP_URL_PATH) ?: '')) : esc_html__('not set', 'mustuse-apps-pub'); ?></p>
            <p style="margin:0;">
                <span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:<?php echo esc_attr($primary); ?>; vertical-align:middle;"></span>
                <code><?php echo esc_html($primary); ?></code>
                &nbsp;
                <span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:<?php echo esc_attr($secondary); ?>; vertical-align:middle; border:1px solid #ddd;"></span>
                <code><?php echo esc_html($secondary); ?></code>
            </p>
        </div>
    </div>
</div>
