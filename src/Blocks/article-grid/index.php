<?php declare(strict_types=1); defined('ABSPATH') || exit;

$postType  = (string) ($a['postType'] ?? 'post');
$count     = max(1, (int) ($a['count'] ?? 6));
$category  = (int) ($a['category'] ?? 0);
$columns   = (string) ($a['columns'] ?? 'auto');
$showThumb = (bool) ($a['showThumbnail'] ?? true);
$showExc   = (bool) ($a['showExcerpt'] ?? false);

$query = [
    'post_type'      => $postType ?: 'post',
    'post_status'    => 'publish',
    'posts_per_page' => $count,
    'no_found_rows'  => true,
];
if ($category > 0) {
    $query['cat'] = $category;
}
$posts = get_posts($query);
?>
<div class="mua-block-article-grid" data-columns="<?php echo esc_attr($columns); ?>">
    <?php if (empty($posts)) : ?>
        <p class="mua-block-placeholder"><?php esc_html_e('No articles to show yet.', 'mustuse-apps-pub'); ?></p>
    <?php else : ?>
        <div class="mua-block-article-grid__items">
            <?php foreach ($posts as $post) :
                $thumb = $showThumb ? get_the_post_thumbnail_url($post, 'medium') : '';
            ?>
                <article class="mua-block-article-grid__item">
                    <?php if ($thumb) : ?>
                        <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr(get_the_title($post)); ?>" />
                    <?php endif; ?>
                    <h4><?php echo esc_html(get_the_title($post)); ?></h4>
                    <?php if ($showExc) : ?>
                        <p><?php echo esc_html(wp_trim_words(get_the_excerpt($post), 15)); ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
