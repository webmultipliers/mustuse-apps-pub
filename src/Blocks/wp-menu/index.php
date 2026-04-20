<?php declare(strict_types=1); defined('ABSPATH') || exit;

$location = (string) ($a['location'] ?? 'primary');
?>
<div class="mua-block-wp-menu mua-block-placeholder">
    <strong><?php esc_html_e('WP Menu', 'mustuse-apps-pub'); ?></strong>
    <p>
        <?php
        /* translators: %s: menu location slug */
        printf( esc_html__('Renders the WP menu registered at location "%s".', 'mustuse-apps-pub'), esc_html($location));
        ?>
    </p>
</div>
