<?php declare(strict_types=1); defined('ABSPATH') || exit;
$title = (string) ($a['title'] ?? 'Stay in the loop');
?>
<div class="mua-block-push-enroll mua-block-placeholder">
    <strong><?php echo esc_html($title); ?></strong>
    <p><?php esc_html_e('Runs the OS push permission flow on device.', 'mustuse-apps-pub'); ?></p>
</div>
