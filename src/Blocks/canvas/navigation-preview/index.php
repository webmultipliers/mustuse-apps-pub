<?php
/**
 * Canvas block: mustuse-apps-pub-canvas/navigation-preview
 *
 * Surfaces the two navigation surfaces a shell will see: the bottom-nav
 * tab bar (capped at five) and the side drawer (the full screen tree).
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$appId   = (int) ($b['postId'] ?? get_the_ID());
$bottom  = [];
$side    = [];
$drawer  = [];

if ($appId > 0
    && get_post_type($appId) === \MustUse\Pub\Data\Models\App::POST_TYPE
    && current_user_can('edit_post', $appId)) {
    $app = \MustUse\Pub\Data\Models\App::find($appId);
    if ($app !== null) {
        $projection = \MustUse\Pub\Routing\ScreenRouteProjector::projectFor($app);
        $bottom     = $projection['navigation'];
        $side       = $projection['side_nav'] ?? [];
        $drawer     = $projection['screen_tree'];
        $bottom     = apply_filters('mua_app_navigation', $bottom, $app);
    }
}

$renderEntry = static function (array $entry, int $depth = 0): string {
    $label  = (string) ($entry['label'] ?? ($entry['title'] ?? ''));
    $target = (string) ($entry['path']  ?? ($entry['screen_id'] ?? ''));
    $indent = $depth * 12;

    $row = '<li class="mua-canvas-block__list-item" style="padding-left:' . $indent . 'px;">'
         . '<strong>' . esc_html($label) . '</strong> ';
    if ($target !== '') {
        $row .= '<code>' . esc_html($target) . '</code>';
    }
    $row .= '</li>';
    return $row;
};
?>
<div class="mua-canvas-block mua-canvas-block--navigation-preview">
    <header class="mua-canvas-block__header">
        <h3 class="mua-canvas-block__title"><?php esc_html_e('Navigation', 'mustuse-apps-pub'); ?></h3>
    </header>

    <section style="margin-bottom:12px;">
        <h4 style="font-size:12px; margin:0 0 6px 0; color:#444;">
            <?php esc_html_e('Bottom nav', 'mustuse-apps-pub'); ?>
            <span style="color:#888; font-weight:normal;">(<?php echo (int) count($bottom); ?>/5)</span>
        </h4>
        <?php if (empty($bottom)) : ?>
            <p class="mua-canvas-block__empty" style="font-size:11px; color:#888;">
                <?php esc_html_e('No screens flagged "show in nav" — the tab bar will be hidden.', 'mustuse-apps-pub'); ?>
            </p>
        <?php else : ?>
            <ul class="mua-canvas-block__list">
                <?php foreach ($bottom as $entry) {
                    if (! is_array($entry)) { continue; }
                    echo $renderEntry($entry, 0);
                } ?>
            </ul>
            <?php if (count($bottom) > 5) : ?>
                <p style="font-size:11px; color:#a55;">
                    <?php esc_html_e('Bottom nav exceeds the 5-item iOS HIG cap. The shell validator will reject this manifest.', 'mustuse-apps-pub'); ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section style="margin-bottom:12px;">
        <h4 style="font-size:12px; margin:0 0 6px 0; color:#444;">
            <?php esc_html_e('Side nav (curated)', 'mustuse-apps-pub'); ?>
            <span style="color:#888; font-weight:normal;">(<?php echo (int) count($side); ?>)</span>
        </h4>
        <?php if (empty($side)) : ?>
            <p class="mua-canvas-block__empty" style="font-size:11px; color:#888;">
                <?php esc_html_e('No screens flagged "show in side nav" — shells fall back to the auto drawer below.', 'mustuse-apps-pub'); ?>
            </p>
        <?php else : ?>
            <ul class="mua-canvas-block__list">
                <?php foreach ($side as $entry) {
                    if (! is_array($entry)) { continue; }
                    echo $renderEntry($entry, 0);
                } ?>
            </ul>
        <?php endif; ?>
    </section>

    <section>
        <h4 style="font-size:12px; margin:0 0 6px 0; color:#444;">
            <?php esc_html_e('Drawer (auto, full tree)', 'mustuse-apps-pub'); ?>
        </h4>
        <?php if (empty($drawer)) : ?>
            <p class="mua-canvas-block__empty" style="font-size:11px; color:#888;">
                <?php esc_html_e('No screens published yet.', 'mustuse-apps-pub'); ?>
            </p>
        <?php else : ?>
            <ul class="mua-canvas-block__list">
                <?php
                $walk = function (array $nodes, int $depth) use (&$walk, $renderEntry): void {
                    foreach ($nodes as $node) {
                        if (! is_array($node)) { continue; }
                        echo $renderEntry($node, $depth);
                        if (! empty($node['children']) && is_array($node['children'])) {
                            $walk($node['children'], $depth + 1);
                        }
                    }
                };
                $walk($drawer, 0);
                ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
