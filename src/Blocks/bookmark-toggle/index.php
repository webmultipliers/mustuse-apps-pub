<?php declare(strict_types=1); defined('ABSPATH') || exit;
$addLabel = (string) ($a['addLabel'] ?? 'Bookmark');
?>
<button class="mua-block-bookmark-toggle" disabled>
    <span aria-hidden="true">☆</span>
    <span><?php echo esc_html($addLabel); ?></span>
</button>
