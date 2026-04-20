<?php declare(strict_types=1); defined('ABSPATH') || exit;

$heading = (string) ($a['heading'] ?? 'Comments');
?>
<div class="mua-block-post-comments mua-block-placeholder">
    <strong><?php echo esc_html($heading); ?></strong>
    <p><?php esc_html_e('Shows the post\'s approved comment thread on the device. Requires Comments enabled on the App.', 'mustuse-apps-pub'); ?></p>
</div>
