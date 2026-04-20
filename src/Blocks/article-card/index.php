<?php declare(strict_types=1); defined('ABSPATH') || exit;

$postId        = $a['postId'] ?? 0;
$showExcerpt   = (bool) ($a['showExcerpt'] ?? true);
$showThumb     = (bool) ($a['showThumbnail'] ?? true);
$excerptLength = (int) ($a['excerptLength'] ?? 20);

$post = $postId ? get_post($postId) : null;
?>
<div class="mua-block-article-card">
    <?php if (! $post) : ?>
        <p class="mua-block-placeholder"><?php esc_html_e('Select an article.', 'mustuse-apps-pub'); ?></p>
    <?php else : ?>
        <?php if ($showThumb) :
            $thumb = get_the_post_thumbnail_url($post, 'medium');
        ?>
            <?php if ($thumb) : ?>
                <img class="mua-block-article-card__thumb" src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr(get_the_title($post)); ?>">
            <?php endif; ?>
        <?php endif; ?>
        <div class="mua-block-article-card__content">
            <h3 class="mua-block-article-card__title"><?php echo esc_html(get_the_title($post)); ?></h3>
            <?php if ($showExcerpt) : ?>
                <p class="mua-block-article-card__excerpt">
                    <?php echo esc_html(wp_trim_words(get_the_excerpt($post), $excerptLength)); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
