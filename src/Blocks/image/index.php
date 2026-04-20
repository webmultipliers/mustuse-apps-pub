<?php declare(strict_types=1); defined('ABSPATH') || exit;

$imageUrl = $a['image']['url'] ?? '';
$caption  = $a['caption'] ?? '';
$alt      = $a['alt'] ?? '';
?>
<figure class="mua-block-image">
    <?php if ($imageUrl) : ?>
        <img src="<?php echo esc_url($imageUrl); ?>" alt="<?php echo esc_attr($alt); ?>">
    <?php else : ?>
        <p class="mua-block-placeholder"><?php esc_html_e('Select an image.', 'mustuse-apps-pub'); ?></p>
    <?php endif; ?>
    <?php if ($caption) : ?>
        <figcaption><?php echo esc_html($caption); ?></figcaption>
    <?php endif; ?>
</figure>
