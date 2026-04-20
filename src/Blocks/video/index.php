<?php declare(strict_types=1); defined('ABSPATH') || exit;

$videoUrl    = $a['videoUrl'] ?? '';
$posterUrl   = $a['posterImage']['url'] ?? '';
$caption     = $a['caption'] ?? '';
?>
<figure class="mua-block-video">
    <?php if ($videoUrl) : ?>
        <div class="mua-block-video__player" data-video-url="<?php echo esc_url($videoUrl); ?>">
            <?php if ($posterUrl) : ?>
                <img class="mua-block-video__poster" src="<?php echo esc_url($posterUrl); ?>" alt="<?php echo esc_attr($caption !== '' ? $caption : __('Video poster', 'mustuse-apps-pub')); ?>">
            <?php endif; ?>
            <span class="mua-block-video__play-icon">&#9654;</span>
        </div>
    <?php else : ?>
        <p class="mua-block-placeholder"><?php esc_html_e('Enter a video URL.', 'mustuse-apps-pub'); ?></p>
    <?php endif; ?>
    <?php if ($caption) : ?>
        <figcaption><?php echo esc_html($caption); ?></figcaption>
    <?php endif; ?>
</figure>
