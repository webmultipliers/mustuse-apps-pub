<?php
/**
 * Canvas block: mustuse-apps-pub-canvas/capability-status
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Manifest\CapabilityRegistry;

$appId    = (int) ($b['postId'] ?? get_the_ID());
$rows     = [];
$shellTag = '';

if ($appId > 0
    && get_post_type($appId) === App::POST_TYPE
    && current_user_can('edit_post', $appId)) {
    $app = App::find($appId);
    if ($app !== null) {
        $advertised = CapabilityRegistry::getAdvertised($app);
        $shellTag   = (string) ($advertised['shell_version'] ?? __('No shell connected', 'mustuse-apps-pub'));
        $native     = $advertised['native_capabilities'] ?? [];
        foreach (CapabilityRegistry::MOBILE_CAPABILITIES as $cap => $classification) {
            $rows[] = [
                'name'           => $cap,
                'classification' => $classification,
                'advertised'     => ! empty($native[$cap]),
            ];
        }
    }
}
?>
<div class="mua-canvas-block mua-canvas-block--capability-status">
    <header class="mua-canvas-block__header">
        <h3 class="mua-canvas-block__title"><?php esc_html_e('Capability status', 'mustuse-apps-pub'); ?></h3>
        <span class="mua-canvas-block__meta"><?php echo esc_html($shellTag); ?></span>
    </header>

    <?php if (empty($rows)) : ?>
        <p class="mua-canvas-block__empty">
            <?php esc_html_e('Capability data will appear once a shell advertises against this app.', 'mustuse-apps-pub'); ?>
        </p>
    <?php else : ?>
        <table class="widefat striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Capability', 'mustuse-apps-pub'); ?></th>
                    <th><?php esc_html_e('Class', 'mustuse-apps-pub'); ?></th>
                    <th><?php esc_html_e('Advertised', 'mustuse-apps-pub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><code><?php echo esc_html($row['name']); ?></code></td>
                        <td><?php echo esc_html($row['classification']); ?></td>
                        <td>
                            <?php if ($row['advertised']) : ?>
                                <span style="color:#0a7a36;">&#10003;</span>
                            <?php else : ?>
                                <span style="color:#888;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
