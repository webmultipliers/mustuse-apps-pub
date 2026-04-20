<?php declare(strict_types=1); defined('ABSPATH') || exit;

$taxonomy = (string) ($a['taxonomy'] ?? 'category');
$count    = max(1, (int) ($a['count'] ?? 8));
$terms    = get_terms([ 'taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => $count, 'orderby' => 'count', 'order' => 'DESC' ]);
?>
<nav class="mua-block-category-pills">
    <?php if (is_wp_error($terms) || empty($terms)) : ?>
        <p class="mua-block-placeholder"><?php esc_html_e('No terms available.', 'mustuse-apps-pub'); ?></p>
    <?php else : ?>
        <ul>
            <?php foreach ($terms as $term) : ?>
                <li><span class="mua-pill"><?php echo esc_html($term->name); ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</nav>
