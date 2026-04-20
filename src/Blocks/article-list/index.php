<?php
/**
 * Block: mustuse-apps-pub/article-list
 *
 * Blockstudio renders this template in the editor and on the frontend.
 * Shells receive the block tree as structured JSON and render natively
 * via their component registry — this PHP output is only seen in the
 * WordPress block editor preview.
 *
 * @var array  $a Block attributes (Blockstudio shorthand).
 * @var array  $b Block context (name, id, classes, etc.).
 */

declare(strict_types=1);

$postType     = $a['postType'] ?? 'post';
$count        = (int) ($a['count'] ?? 10);
$category     = (int) ($a['category'] ?? 0);
$showExcerpt  = (bool) ($a['showExcerpt'] ?? true);
$showThumb    = (bool) ($a['showThumbnail'] ?? true);

$queryArgs = [
    'post_type'      => $postType,
    'posts_per_page' => $count,
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
];

if ($category > 0) {
    $queryArgs['cat'] = $category;
}

// Cache output in a short-lived transient to avoid DB hammering during editor typing
$cache_key = 'mua_article_list_' . md5(serialize($queryArgs));
$posts = get_transient($cache_key);
if ($posts === false) {
    $posts = get_posts($queryArgs);
    set_transient($cache_key, $posts, 30); // 30 seconds
}
?>
<div class="mua-block-article-list">
    <?php if (empty($posts)) : ?>
        <p class="mua-block-placeholder">
            <?php esc_html_e('No articles found. Adjust the block settings.', 'mustuse-apps-pub'); ?>
        </p>
    <?php else : ?>
        <ul class="mua-article-list">
            <?php foreach ($posts as $post) : ?>
                <li class="mua-article-list__item">
                    <?php if ($showThumb) :
                        $thumb = get_the_post_thumbnail_url($post, 'thumbnail');
                    ?>
                        <?php if ($thumb) : ?>
                            <img class="mua-article-list__thumb" src="<?php echo esc_url($thumb); ?>" alt="" width="48" height="48">
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="mua-article-list__content">
                        <span class="mua-article-list__title"><?php echo esc_html(get_the_title($post)); ?></span>
                        <?php if ($showExcerpt) : ?>
                            <span class="mua-article-list__excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt($post), 15)); ?></span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
