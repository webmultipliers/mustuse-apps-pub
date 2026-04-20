<?php declare(strict_types=1); defined('ABSPATH') || exit;
$content = $a['content'] ?? '';

/**
 * Editor preview must match the shell-side contract declared in
 * native.json (`allowed_tags`). Using `wp_kses_post` here would let the
 * editor render tags (h1–h6, blockquote, table, img, …) that shells then
 * strip — confusing authors and making the preview a poor proxy for the
 * shipped UI. Keep this list byte-for-byte aligned with native.json.
 */
$allowed = [
    'p'      => [],
    'br'     => [],
    'strong' => [],
    'em'     => [],
    'a'      => ['href' => true, 'rel' => true, 'target' => true],
    'ul'     => [],
    'ol'     => [],
    'li'     => [],
    'code'   => [],
];
?>
<div class="mua-block-text">
    <?php echo wp_kses($content, $allowed); ?>
</div>
