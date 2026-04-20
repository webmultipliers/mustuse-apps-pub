<?php declare(strict_types=1); defined('ABSPATH') || exit;

$gateHeading     = $a['gateHeading'] ?? 'Authentication required';
$gateDescription = $a['gateDescription'] ?? '';
$ctaLabel        = $a['ctaLabel'] ?? 'Unlock Content';
$innerContent    = $b['innerContent'] ?? '';
?>
<div class="mua-block-auth-gate" data-capability="biometrics">
    <div class="mua-block-auth-gate__prompt">
        <?php if ($gateHeading) : ?>
            <h3 class="mua-block-auth-gate__heading"><?php echo esc_html($gateHeading); ?></h3>
        <?php endif; ?>
        <?php if ($gateDescription) : ?>
            <p class="mua-block-auth-gate__description"><?php echo esc_html($gateDescription); ?></p>
        <?php endif; ?>
        <button type="button" class="mua-block-auth-gate__cta"><?php echo esc_html($ctaLabel); ?></button>
    </div>
    <?php
    $is_editor = is_admin() && !defined('REST_REQUEST');
    $display_style = $is_editor ? 'display:block;' : 'display:none;';
    ?>
    <div class="mua-block-auth-gate__content" style="<?php echo esc_attr($display_style); ?>">
        <?php echo $innerContent; ?>
    </div>
</div>
