<?php declare(strict_types=1); defined('ABSPATH') || exit;

$label    = (string) ($a['label'] ?? 'Breaking');
$headline = (string) ($a['headline'] ?? '');
$linkUrl  = (string) ($a['linkUrl'] ?? '');
?>
<div class="mua-block-breaking-banner">
    <strong><?php echo esc_html($label); ?></strong>
    <span><?php echo esc_html($headline ?: __('Set a headline.', 'mustuse-apps-pub')); ?></span>
    <?php if ($linkUrl) : ?>
        <small><?php echo esc_html($linkUrl); ?></small>
    <?php endif; ?>
</div>
