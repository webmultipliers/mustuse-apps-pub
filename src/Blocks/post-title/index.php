<?php declare(strict_types=1); defined('ABSPATH') || exit;

$level = (string) ($a['level'] ?? 'h1');
$title = esc_html(get_the_title()) ?: '<em>(post title renders on device)</em>';
?>
<?php echo match ($level) {
    'h2'    => '<h2 class="mua-block-post-title">' . $title . '</h2>',
    'h3'    => '<h3 class="mua-block-post-title">' . $title . '</h3>',
    default => '<h1 class="mua-block-post-title">' . $title . '</h1>',
}; ?>
