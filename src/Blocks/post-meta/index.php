<?php declare(strict_types=1); defined('ABSPATH') || exit;

$metaKey = (string) ($a['metaKey'] ?? '');
$label   = (string) ($a['label'] ?? '');
?>
<div class="mua-block-post-meta">
    <?php if ($metaKey === '') : ?>
        <p class="mua-block-placeholder"><?php esc_html_e('Set a meta key in the inspector.', 'mustuse-apps-pub'); ?></p>
    <?php else : ?>
        <p class="mua-block-placeholder">
            <?php echo esc_html($label !== '' ? $label . ': ' : ''); ?>
            <code><?php echo esc_html('{ post.meta["' . $metaKey . '"] }'); ?></code>
        </p>
    <?php endif; ?>
</div>
