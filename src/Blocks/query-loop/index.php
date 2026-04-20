<?php declare(strict_types=1); defined('ABSPATH') || exit; ?>
<div class="mua-block-query-loop">
    <header class="mua-block-query-loop__header">
        <strong><?php esc_html_e('Query Loop', 'mustuse-apps-pub'); ?></strong>
        <span><?php esc_html_e('Drop a Post Template inside, then put your card blocks (post-title, post-excerpt, post-featured-image, …) inside that.', 'mustuse-apps-pub'); ?></span>
    </header>
    <InnerBlocks allowedBlocks='["mustuse-apps-pub/post-template"]' template='[["mustuse-apps-pub/post-template"]]' />
</div>
