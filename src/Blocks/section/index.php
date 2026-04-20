<?php declare(strict_types=1); defined('ABSPATH') || exit;

$heading = (string) ($a['heading'] ?? '');
$seeAllLabel = (string) ($a['seeAllLabel'] ?? '');
$seeAllUrl   = (string) ($a['seeAllUrl']   ?? '');
?>
<section class="mua-block-section">
    <?php if ($heading || $seeAllLabel) : ?>
        <header class="mua-block-section__header">
            <?php if ($heading) : ?><h3><?php echo esc_html($heading); ?></h3><?php endif; ?>
            <?php if ($seeAllLabel && $seeAllUrl) : ?>
                <a href="<?php echo esc_url($seeAllUrl); ?>"><?php echo esc_html($seeAllLabel); ?></a>
            <?php endif; ?>
        </header>
    <?php endif; ?>
    <InnerBlocks />
</section>
