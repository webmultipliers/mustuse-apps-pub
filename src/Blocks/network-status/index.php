<?php declare(strict_types=1); defined('ABSPATH') || exit;

$title       = (string) ($a['title']       ?? 'Network');
$offlineText = (string) ($a['offlineText'] ?? "You're offline.");
?>
<div class="mua-block-network-status mua-block-placeholder">
    <strong><?php echo esc_html($title); ?></strong>
    <p><?php esc_html_e('Shows live connectivity on the device (WiFi/Cellular, expensive, constrained).', 'mustuse-apps-pub'); ?></p>
    <small><?php echo esc_html($offlineText); ?></small>
</div>
