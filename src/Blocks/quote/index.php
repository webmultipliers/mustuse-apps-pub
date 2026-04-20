<?php declare(strict_types=1); defined('ABSPATH') || exit;
$text = (string) ($a['text'] ?? '');
$cite = (string) ($a['cite'] ?? '');
?>
<blockquote class="mua-block-quote">
    <p><?php echo esc_html($text); ?></p>
    <?php if ($cite) : ?><cite>— <?php echo esc_html($cite); ?></cite><?php endif; ?>
</blockquote>
