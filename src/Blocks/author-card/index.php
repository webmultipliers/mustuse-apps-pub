<?php declare(strict_types=1); defined('ABSPATH') || exit;

$authorId = (int) ($a['authorId'] ?? 0);
$showBio  = (bool) ($a['showBio'] ?? true);
$user     = $authorId ? get_userdata($authorId) : null;
?>
<div class="mua-block-author-card">
    <?php if (! $user) : ?>
        <p class="mua-block-placeholder"><?php esc_html_e('Select an author.', 'mustuse-apps-pub'); ?></p>
    <?php else : ?>
        <img src="<?php echo esc_url((string) get_avatar_url($authorId, ['size' => 96])); ?>" alt="<?php echo esc_attr($user->display_name); ?>" width="96" height="96" />
        <div>
            <h4><?php echo esc_html($user->display_name); ?></h4>
            <?php if ($showBio) : ?>
                <p><?php echo esc_html(get_user_meta($authorId, 'description', true)); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
