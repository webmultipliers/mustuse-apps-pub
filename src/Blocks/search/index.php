<?php declare(strict_types=1); defined('ABSPATH') || exit;
$placeholder = (string) ($a['placeholder']  ?? 'Search articles…');
$submitLabel = (string) ($a['submitLabel']  ?? 'Search');
?>
<div class="mua-block-search">
    <div class="mua-block-search__row">
        <input type="search" placeholder="<?php echo esc_attr($placeholder); ?>" disabled />
        <button type="button" disabled><?php echo esc_html($submitLabel); ?></button>
    </div>
    <p class="mua-block-placeholder">
        <?php esc_html_e('Search is interactive on device — submitting navigates to /search?q={query}.', 'mustuse-apps-pub'); ?>
    </p>
</div>
