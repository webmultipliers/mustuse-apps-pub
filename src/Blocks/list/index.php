<?php declare(strict_types=1); defined('ABSPATH') || exit;

$style    = (string) ($a['style'] ?? 'bullet');
$split    = preg_split('/\R/', (string) ($a['items'] ?? ''));
$items    = is_array($split) ? array_values(array_filter(array_map('trim', $split))) : [];
$numbered = $style === 'numbered';
?>
<?php if (empty($items)) : ?>
    <p class="mua-block-placeholder"><?php esc_html_e('Add one item per line.', 'mustuse-apps-pub'); ?></p>
<?php elseif ($numbered) : ?>
    <ol class="mua-block-list mua-block-list--<?php echo esc_attr($style); ?>">
        <?php foreach ($items as $item) : ?>
            <li><?php echo esc_html($item); ?></li>
        <?php endforeach; ?>
    </ol>
<?php else : ?>
    <ul class="mua-block-list mua-block-list--<?php echo esc_attr($style); ?>">
        <?php foreach ($items as $item) : ?>
            <li><?php echo esc_html($item); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
