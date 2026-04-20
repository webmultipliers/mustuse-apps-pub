<?php declare(strict_types=1); defined('ABSPATH') || exit;

$text  = $a['text'] ?? '';
$level = $a['level'] ?? 'h2';

// Emit the whole open/close pair as static strings so the dynamic value
// can never reach the output template — even with an upstream allowlist
// drift, the renderer can only emit known-safe markup.
$tags = match ($level) {
    'h3' => ['<h3>', '</h3>'],
    'h4' => ['<h4>', '</h4>'],
    default => ['<h2>', '</h2>'],
};
?>
<div class="mua-block-section-heading">
    <?php echo $tags[0]; ?><?php echo esc_html($text); ?><?php echo $tags[1]; ?>
    <hr class="mua-block-section-heading__rule">
</div>
