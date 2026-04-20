<?php
/**
 * Preview dashboard — full-page render.
 *
 * Variables come from {@see \MustUse\Pub\Preview\PreviewRoute::buildContext()}
 * via the $context local set by the route's `require`.
 *
 * @var array{
 *   mode: 'app'|'screen',
 *   post: \WP_Post,
 *   app: \MustUse\Pub\Data\Models\App,
 *   active_screen_id: ?int,
 *   panes: list<array{id: int, slug: string, title: string, html: string}>,
 *   navigation: list<array{id: int, slug: string, title: string, icon: string}>,
 *   branding: array<string, mixed>,
 *   bundle_id: string,
 *   version_name: string,
 *   capabilities: list<array{label: string, key: string, status: string, value: string}>,
 *   manifest_summary: list<array{key: string, value: string, highlight: bool}>,
 *   checklist: list<array{label: string, status: string, hint?: string, node?: int}>,
 *   is_autosave: bool,
 *   editor_url: string,
 *   splash_image: string,
 * } $context
 */

defined('ABSPATH') || exit;

$mode             = $context['mode'];
$app              = $context['app'];
$post             = $context['post'];
$panes            = $context['panes'];
$navigation       = $context['navigation'];
$activeScreenId   = $context['active_screen_id'];
$branding         = $context['branding'];
$capabilities     = $context['capabilities'];
$manifestSummary  = $context['manifest_summary'];
$checklist        = $context['checklist'];
$isAutosave       = $context['is_autosave'];
$editorUrl        = $context['editor_url'];
$splashImage      = $context['splash_image'];

$primary    = (string) ($branding['primary_color'] ?? '#1d4ed8');
$bgColor    = (string) ($branding['background_color'] ?? '#ffffff');
$iconUrl    = (string) ($branding['icon_url'] ?? '');
$initial    = mb_strtoupper(mb_substr($app->title(), 0, 1)) ?: 'A';

// Status-bar tint inferred from background luminance: bright bg → dark
// glyphs, dark bg → light glyphs. Mirrors NativePHP's `auto` style.
$relativeLuminance = static function (string $hex): float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return 1.0;
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
};
$statusBarDark = $relativeLuminance($bgColor) > 0.6;

$statusLabel = $isAutosave
    ? __('Previewing latest autosave', 'mustuse-apps-pub')
    : __('Previewing saved draft', 'mustuse-apps-pub');

$contextLabel = $mode === 'app'
    ? sprintf(__('App preview · %s', 'mustuse-apps-pub'), $app->title())
    : sprintf(__('Screen preview · %s', 'mustuse-apps-pub'), get_the_title($post));
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($contextLabel); ?></title>
    <style id="mua-preview-inline-vars">
        :root {
            --preview-splash-accent: <?php echo esc_html($primary); ?>;
            --preview-device-bg: <?php echo esc_html($bgColor); ?>;
        }
    </style>
    <?php wp_head(); ?>
</head>
<body class="mua-preview">

<!-- Splash overlay — auto-dismisses after ~1.5s, manual skip available. -->
<div class="mua-preview-splash<?php echo $splashImage !== '' ? ' mua-preview-splash--image' : ''; ?>"
     style="background: <?php echo esc_attr($primary); ?>;"
     data-mua-splash>
    <?php if ($splashImage !== '') : ?>
        <div class="mua-preview-splash__image" style="background-image: url('<?php echo esc_url($splashImage); ?>');" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="mua-preview-splash__logo">
        <?php if ($iconUrl !== '') : ?>
            <img src="<?php echo esc_url($iconUrl); ?>" alt="">
        <?php else : ?>
            <?php echo esc_html($initial); ?>
        <?php endif; ?>
    </div>
    <div class="mua-preview-splash__meta"><?php esc_html_e('NativePHP Mobile Runtime', 'mustuse-apps-pub'); ?></div>
    <button type="button" class="mua-preview-splash__skip" data-mua-splash-skip>
        <?php esc_html_e('Skip', 'mustuse-apps-pub'); ?>
    </button>
</div>

<header class="mua-preview-topbar">
    <div class="mua-preview-topbar__brand">
        <span class="mua-preview-topbar__brand-title"><?php esc_html_e('MustUse', 'mustuse-apps-pub'); ?></span>
        <span class="mua-preview-topbar__brand-context"><?php echo esc_html($contextLabel); ?></span>
        <div class="mua-preview-topbar__status<?php echo $isAutosave ? '' : ' mua-preview-topbar__status--draft'; ?>">
            <span class="mua-preview-topbar__status-dot"></span>
            <?php echo esc_html($statusLabel); ?>
        </div>
    </div>
    <div class="mua-preview-topbar__actions">
        <span class="mua-preview-topbar__build-id">
            <?php
            $lastCommit = (string) get_post_meta($app->id(), '_mua_last_build_commit', true);
            if ($lastCommit !== '') {
                echo 'BUILD ' . esc_html(substr($lastCommit, 0, 7));
            }
            ?>
        </span>
        <a class="mua-preview-topbar__btn" href="<?php echo esc_url($editorUrl); ?>">
            <?php esc_html_e('Back to editor', 'mustuse-apps-pub'); ?>
        </a>
    </div>
</header>

<main class="mua-preview-grid">
    <!-- Left column: identity + capabilities -->
    <section>
        <div class="mua-preview-identity">
            <div class="mua-preview-identity__icon">
                <?php if ($iconUrl !== '') : ?>
                    <img src="<?php echo esc_url($iconUrl); ?>" alt="">
                <?php else : ?>
                    <?php echo esc_html($initial); ?>
                <?php endif; ?>
            </div>
            <h1 class="mua-preview-identity__name"><?php echo esc_html($app->title()); ?></h1>
            <p class="mua-preview-identity__meta">
                <?php echo esc_html($context['bundle_id']); ?> · v<?php echo esc_html($context['version_name']); ?>
            </p>
        </div>

        <div class="mua-preview-card">
            <h2 class="mua-preview-card__title">
                <?php esc_html_e('Native APIs', 'mustuse-apps-pub'); ?>
                <span><?php esc_html_e('Per-screen', 'mustuse-apps-pub'); ?></span>
            </h2>
            <ul class="mua-preview-list">
                <?php foreach ($capabilities as $cap) :
                    $modifier = $cap['status'] === 'on' ? 'on' : ($cap['status'] === 'info' ? 'warn' : '');
                    ?>
                    <li class="mua-preview-list__item">
                        <span class="mua-preview-list__label"><?php echo esc_html($cap['label']); ?></span>
                        <span class="mua-preview-list__badge<?php echo $modifier !== '' ? ' mua-preview-list__badge--' . esc_attr($modifier) : ''; ?>">
                            <?php echo esc_html($cap['value']); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <!-- Center column: device frame -->
    <section class="mua-preview-device-wrap" data-mua-frame>
        <div class="mua-preview-device">
            <div class="mua-preview-device__screen">
                <div class="mua-preview-device__status<?php echo $statusBarDark ? '' : ' mua-preview-device__status--dark'; ?>">
                    <span>9:41</span>
                    <span><?php echo $statusBarDark ? '●●●●' : '○○○○'; ?></span>
                </div>
                <div class="mua-preview-device__body">
                    <?php foreach ($panes as $pane) : ?>
                        <article class="mua-preview-device__pane"
                                 data-mua-screen-pane="<?php echo esc_attr((string) $pane['id']); ?>"
                                 <?php echo $pane['id'] === $activeScreenId ? '' : 'hidden'; ?>>
                            <?php
                            // do_blocks already ran via the_content filter
                            // in PreviewRoute::paneFor(). Output is escaped
                            // by KSES through the same filter chain WP uses
                            // for normal post output.
                            echo $pane['html'];
                            ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if (! empty($navigation)) : ?>
                    <nav class="mua-preview-device__nav" aria-label="<?php esc_attr_e('App navigation', 'mustuse-apps-pub'); ?>">
                        <?php foreach ($navigation as $nav) :
                            $isActive = $nav['id'] === $activeScreenId;
                            ?>
                            <button type="button"
                                    class="mua-preview-device__nav-tab<?php echo $isActive ? ' is-active' : ''; ?>"
                                    data-mua-nav-tab="<?php echo esc_attr((string) $nav['id']); ?>"
                                    <?php echo $mode === 'screen' ? 'disabled' : ''; ?>>
                                <span class="mua-preview-device__nav-icon"><?php echo esc_html($nav['icon']); ?></span>
                                <span><?php echo esc_html($nav['title']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
        <p class="mua-preview-frame-caption">
            <?php
            if ($mode === 'app') {
                esc_html_e('Tap a tab below the device to switch screens.', 'mustuse-apps-pub');
            } else {
                esc_html_e('Native action buttons are visible but only fire on a real device.', 'mustuse-apps-pub');
            }
            ?>
        </p>
    </section>

    <!-- Right column: manifest summary + checklist -->
    <section>
        <div class="mua-preview-card">
            <h2 class="mua-preview-card__title">
                <?php esc_html_e('Manifest summary', 'mustuse-apps-pub'); ?>
                <span>JSON</span>
            </h2>
            <div class="mua-preview-terminal">
                <?php foreach ($manifestSummary as $row) : ?>
                    <div class="mua-preview-terminal__row">
                        <span class="mua-preview-terminal__key"><?php echo esc_html($row['key']); ?></span>
                        <span class="mua-preview-terminal__val<?php echo $row['highlight'] ? ' mua-preview-terminal__val--highlight' : ''; ?>">
                            <?php echo esc_html($row['value']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mua-preview-card">
            <h2 class="mua-preview-card__title">
                <?php esc_html_e('Build checklist', 'mustuse-apps-pub'); ?>
                <span><?php echo (int) count(array_filter($checklist, static fn ($c) => ($c['status'] ?? '') === 'pass')); ?>/<?php echo (int) count($checklist); ?></span>
            </h2>
            <ul class="mua-preview-checklist">
                <?php foreach ($checklist as $check) :
                    $status = (string) ($check['status'] ?? 'info');
                    $glyph  = $status === 'pass' ? '✓' : ($status === 'fail' ? '✕' : 'i');
                    ?>
                    <li class="mua-preview-checklist__item">
                        <span class="mua-preview-checklist__indicator mua-preview-checklist__indicator--<?php echo esc_attr($status); ?>">
                            <?php echo esc_html($glyph); ?>
                        </span>
                        <span class="mua-preview-checklist__content">
                            <span class="mua-preview-checklist__label"><?php echo esc_html((string) ($check['label'] ?? '')); ?></span>
                            <?php if (! empty($check['hint'])) : ?>
                                <span class="mua-preview-checklist__hint"><?php echo esc_html((string) $check['hint']); ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
</main>

<?php wp_footer(); ?>
</body>
</html>
