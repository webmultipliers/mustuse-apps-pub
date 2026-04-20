<?php
/**
 * Canvas block: mustuse-apps-pub-canvas/manifest-preview
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$appId    = (int) ($b['postId'] ?? get_the_ID());
$manifest = null;
$error    = '';

// Canvas blocks display app internals (manifest JSON, env-var names,
// capability matrix). The parent CPT is public => true so the Gutenberg
// Preview button works; gate rendering on edit capability so the public
// permalink doesn't expose these to anonymous visitors.
if ($appId > 0
    && get_post_type($appId) === \MustUse\Pub\Data\Models\App::POST_TYPE
    && current_user_can('edit_post', $appId)) {
    $app = \MustUse\Pub\Data\Models\App::find($appId);
    if ($app !== null) {
        try {
            $manifest = (new \MustUse\Pub\Manifest\ManifestBuilder())->build($app);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<div class="mua-canvas-block mua-canvas-block--manifest-preview">
    <header class="mua-canvas-block__header">
        <h3 class="mua-canvas-block__title"><?php esc_html_e('Manifest', 'mustuse-apps-pub'); ?></h3>
    </header>

    <?php if ($error !== '') : ?>
        <p class="mua-canvas-block__error" style="color:#a00;">
            <?php echo esc_html($error); ?>
        </p>
    <?php elseif ($manifest === null) : ?>
        <p class="mua-canvas-block__empty">
            <?php esc_html_e('Manifest will appear here once the app is saved.', 'mustuse-apps-pub'); ?>
        </p>
    <?php else : ?>
        <pre style="max-height:320px; overflow:auto; background:#1f1f1f; color:#d8e6f3; padding:12px; border-radius:6px; font-size:11px; line-height:1.5;"><code><?php echo esc_html((string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>
    <?php endif; ?>
</div>
