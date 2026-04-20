<?php
/**
 * Canvas block: mustuse-apps-pub-canvas/mobile-preview
 *
 * A static device frame in the editor showing the resolved app type and
 * the screens the shell will receive — including each screen's path,
 * home flag, and whether it lands in the bottom-nav.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$appId         = (int) ($b['postId'] ?? get_the_ID());
$appType       = 'mobile_ios';
$primary       = '#1a1a1a';
$rows          = [];
$tabSlugs      = [];
$sideSlugs     = [];
$homeSlug      = '';

if ($appId > 0
    && get_post_type($appId) === \MustUse\Pub\Data\Models\App::POST_TYPE
    && current_user_can('edit_post', $appId)) {
    $appType = (string) (get_post_meta($appId, '_mua_app_type', true) ?: 'mobile_ios');
    $branding = get_post_meta($appId, '_mua_branding', true);
    if (is_array($branding)) {
        $primary = (string) ($branding['primary_color'] ?? ($branding['primaryColor'] ?? $primary));
    }

    $app = \MustUse\Pub\Data\Models\App::find($appId);
    if ($app !== null) {
        $projection = \MustUse\Pub\Routing\ScreenRouteProjector::projectFor($app);
        $rows       = $projection['screens'];
        foreach ($projection['navigation'] as $tab) {
            $tabSlugs[] = (string) ($tab['screen_id'] ?? '');
        }
        foreach (($projection['side_nav'] ?? []) as $entry) {
            $sideSlugs[] = (string) ($entry['screen_id'] ?? '');
        }
        foreach ($rows as $row) {
            if (! empty($row['is_home'])) {
                $homeSlug = (string) ($row['screen_id'] ?? '');
                break;
            }
        }
    }
}

$frameLabel = match ($appType) {
    'mobile_ios'     => __('iOS preview', 'mustuse-apps-pub'),
    'mobile_android' => __('Android preview', 'mustuse-apps-pub'),
    default          => __('Mobile preview', 'mustuse-apps-pub'),
};
?>
<div class="mua-canvas-block mua-canvas-block--mobile-preview">
    <header class="mua-canvas-block__header">
        <h3 class="mua-canvas-block__title"><?php echo esc_html($frameLabel); ?></h3>
    </header>
    <div style="display:flex; justify-content:center; padding:16px 0;">
        <div style="width:260px; height:460px; border:8px solid #222; border-radius:36px; background:#fafafa; padding:14px 12px; display:flex; flex-direction:column; gap:6px; box-shadow:0 6px 24px rgba(0,0,0,0.08);">
            <div style="background:<?php echo esc_attr($primary); ?>; height:36px; border-radius:6px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">
                <?php echo esc_html(get_the_title($appId)); ?>
            </div>
            <?php if (empty($rows)) : ?>
                <p style="text-align:center; color:#888; font-size:11px; margin-top:auto; margin-bottom:auto;">
                    <?php esc_html_e('No screens yet. Add one to populate the manifest.', 'mustuse-apps-pub'); ?>
                </p>
            <?php else : ?>
                <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:4px; overflow-y:auto;">
                    <?php foreach ($rows as $row) :
                        $slug    = (string) ($row['screen_id'] ?? '');
                        $title   = (string) ($row['title']     ?? $slug);
                        $path    = (string) ($row['path']      ?? '/' . $slug);
                        $isHome  = ! empty($row['is_home']);
                        $inTabs  = in_array($slug, $tabSlugs, true);
                        ?>
                        <li style="background:#fff; border:1px solid #e5e5e5; border-radius:5px; padding:6px 8px; font-size:11px; display:flex; flex-direction:column; gap:2px;">
                            <div style="display:flex; align-items:center; gap:6px;">
                                <strong style="font-weight:600;"><?php echo esc_html($title); ?></strong>
                                <?php if ($isHome) : ?>
                                    <span title="<?php esc_attr_e('Home — opens at /', 'mustuse-apps-pub'); ?>" style="font-size:9px; padding:1px 5px; background:#e7f3ff; color:#1d4ed8; border-radius:8px;">home</span>
                                <?php endif; ?>
                                <?php if ($inTabs) : ?>
                                    <span title="<?php esc_attr_e('Visible in bottom-nav', 'mustuse-apps-pub'); ?>" style="font-size:9px; padding:1px 5px; background:#eef7ee; color:#1f7a3f; border-radius:8px;">tab</span>
                                <?php endif; ?>
                                <?php if (in_array($slug, $sideSlugs, true)) : ?>
                                    <span title="<?php esc_attr_e('In curated side menu', 'mustuse-apps-pub'); ?>" style="font-size:9px; padding:1px 5px; background:#fff5e6; color:#a35d00; border-radius:8px;">side</span>
                                <?php endif; ?>
                            </div>
                            <code style="font-size:10px; color:#666; background:#f3f3f3; padding:1px 4px; border-radius:3px; align-self:flex-start;">
                                <?php echo esc_html($path); ?>
                            </code>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (! empty($tabSlugs)) : ?>
                    <div style="margin-top:auto; border-top:1px solid #e5e5e5; padding-top:6px; display:flex; gap:4px; justify-content:space-around;">
                        <?php foreach ($tabSlugs as $slug) : ?>
                            <span style="font-size:9px; color:#444; background:#fff; border:1px solid #ddd; border-radius:4px; padding:3px 5px;">
                                <?php echo esc_html($slug); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($homeSlug === '' && ! empty($rows)) : ?>
        <p style="font-size:11px; color:#a55; margin-top:8px;">
            <?php esc_html_e('No screen is flagged as Home — the shell will fall back to the first nav-visible screen. Set "Use as home" on the screen you want at /.', 'mustuse-apps-pub'); ?>
        </p>
    <?php endif; ?>
</div>
