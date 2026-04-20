<?php declare(strict_types=1); defined('ABSPATH') || exit;

$title        = (string) ($a['title']        ?? 'Device');
$showBattery  = (bool)   ($a['showBattery']  ?? true);
$showPlatform = (bool)   ($a['showPlatform'] ?? true);
$showModel    = (bool)   ($a['showModel']    ?? true);
?>
<div class="mua-block-device-info mua-block-placeholder">
    <strong><?php echo esc_html($title); ?></strong>
    <ul>
        <?php if ($showPlatform) : ?><li><?php esc_html_e('Platform & OS', 'mustuse-apps-pub'); ?></li><?php endif; ?>
        <?php if ($showModel)    : ?><li><?php esc_html_e('Device model', 'mustuse-apps-pub'); ?></li><?php endif; ?>
        <?php if ($showBattery)  : ?><li><?php esc_html_e('Battery level', 'mustuse-apps-pub'); ?></li><?php endif; ?>
    </ul>
    <small><?php esc_html_e('Renders live values from the device on the mobile shell.', 'mustuse-apps-pub'); ?></small>
</div>
