<?php declare(strict_types=1); defined('ABSPATH') || exit;

$label       = (string) ($a['label']       ?? '');
$description = (string) ($a['description'] ?? '');
$path        = (string) ($a['path']        ?? '/');
?>
<div class="mua-block-screen-link mua-block-placeholder">
    <strong><?php echo esc_html($label !== '' ? $label : __('Screen link', 'mustuse-apps-pub')); ?></strong>
    <?php if ($description !== '') : ?>
        <p><?php echo esc_html($description); ?></p>
    <?php endif; ?>
    <small><?php echo esc_html($path); ?></small>
</div>
