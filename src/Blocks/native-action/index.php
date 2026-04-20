<?php declare(strict_types=1); defined('ABSPATH') || exit;

$capability   = $a['capability'] ?? 'share';
$label        = $a['label'] ?? 'Tap to activate';
$fallback     = $a['fallbackText'] ?? '';
$callbackType = $a['callbackType'] ?? 'state';
$callbackSlot = $a['callbackSlot'] ?? '';
$callbackUrl  = $a['callbackUrl'] ?? '';
?>
<div class="mua-block-native-action"
     data-capability="<?php echo esc_attr($capability); ?>"
     data-callback-type="<?php echo esc_attr($callbackType); ?>"
     <?php if ($callbackType === 'state' && $callbackSlot) : ?>
         data-callback-slot="<?php echo esc_attr($callbackSlot); ?>"
     <?php elseif ($callbackType === 'endpoint' && $callbackUrl) : ?>
         data-callback-url="<?php echo esc_attr($callbackUrl); ?>"
     <?php endif; ?>
>
    <button type="button" class="mua-block-native-action__btn"><?php echo esc_html($label); ?></button>
    <?php if ($fallback) : ?>
        <p class="mua-block-native-action__fallback"><?php echo esc_html($fallback); ?></p>
    <?php endif; ?>
</div>
