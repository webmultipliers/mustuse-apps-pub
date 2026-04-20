<?php declare(strict_types=1); defined('ABSPATH') || exit;

$heading        = $a['heading'] ?? '';
$subheading     = $a['subheading'] ?? '';
$imageId        = $a['imageUrl']['id'] ?? 0;
$imageUrl       = $imageId ? wp_get_attachment_image_url($imageId, 'large') : ($a['imageUrl']['url'] ?? '');
$ctaLabel       = $a['ctaLabel'] ?? '';
$ctaTargetType  = $a['ctaTargetType'] ?? 'none';
$ctaTargetValue = $a['ctaTargetValue'] ?? '';
?>
<div class="mua-block-hero" <?php echo $imageUrl ? 'style="background-image:url(' . esc_url($imageUrl) . ')"' : ''; ?>>
    <?php if ($heading) : ?><h1><?php echo esc_html($heading); ?></h1><?php endif; ?>
    <?php if ($subheading) : ?><p><?php echo esc_html($subheading); ?></p><?php endif; ?>
    <?php if ($ctaLabel && $ctaTargetType !== 'none') : ?>
        <span class="mua-block-hero__cta"
              data-target-type="<?php echo esc_attr($ctaTargetType); ?>"
              data-target-value="<?php echo esc_attr($ctaTargetValue); ?>">
            <?php echo esc_html($ctaLabel); ?>
        </span>
    <?php endif; ?>
</div>
