<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Support\ManifestSigner;

/**
 * Computes per-node deployment readiness checks for the Deployment Map.
 *
 * Each check is `{label, status: 'pass'|'fail'|'info', node: 1-5}`.
 * The built-in checks cover the Publisher's own responsibility; third-party
 * extensions contribute additional checks via `mua_app_requirements_check`.
 */
final class DeploymentRequirements
{
    /**
     * @return list<array{label: string, status: 'pass'|'fail'|'info', node: int}>
     */
    public static function checksForApp(App $app): array
    {
        $checks = [];

        $screens = Screen::findByApp($app->id(), [
            'post_status' => ['publish', 'draft', 'pending'],
            'numberposts' => 1,
        ]);
        $checks[] = [
            'label'  => __('At least one screen configured', 'mustuse-apps-pub'),
            'status' => ! empty($screens) ? 'pass' : 'fail',
            'node'   => 1,
        ];

        // Every detail screen MUST have a `_mua_deeplink_path` template,
        // or it will never match an incoming URL on the device. Ship It
        // fails loud here instead of silently projecting an unreachable
        // screen. List the offenders so the publisher can fix them.
        $detailScreens   = Screen::findByApp($app->id(), ['post_status' => 'publish']);
        $missingPattern  = [];
        foreach ($detailScreens as $screen) {
            if ($screen->role() !== 'detail') {
                continue;
            }
            $pattern = (string) get_post_meta($screen->id(), '_mua_deeplink_path', true);
            if ($pattern === '') {
                $missingPattern[] = $screen->title();
            }
        }
        $checks[] = [
            'label'  => empty($missingPattern)
                ? __('Detail screens all have URL patterns', 'mustuse-apps-pub')
                : \sprintf(
                    /* translators: %s: comma-separated list of screen titles */
                    __('Detail screens missing URL patterns: %s', 'mustuse-apps-pub'),
                    \implode(', ', $missingPattern)
                ),
            'status' => empty($missingPattern) ? 'pass' : 'fail',
            'node'   => 1,
        ];

        $signingKey = ManifestSigner::getOrCreateKey();
        $checks[] = [
            'label'  => __('Manifest signing key active', 'mustuse-apps-pub'),
            'status' => $signingKey !== '' ? 'pass' : 'fail',
            'node'   => 1,
        ];

        // Authored 404 screen — makes the fallback cascade graceful.
        $any404 = Screen::findFallback($app->id(), ['any']);
        $fallbackPolicy = (string) $app->meta('fallback_policy', 'web');
        $requires404    = $fallbackPolicy === 'screen_404';
        $checks[] = [
            'label'  => $any404 instanceof Screen
                ? __('Authored 404 screen present', 'mustuse-apps-pub')
                : ($requires404
                    ? __('Fallback policy requires a 404 screen — none authored', 'mustuse-apps-pub')
                    : __('No 404 screen authored (shell falls back to web view)', 'mustuse-apps-pub')),
            'status' => $any404 instanceof Screen
                ? 'pass'
                : ($requires404 ? 'fail' : 'info'),
            'node'   => 1,
        ];

        // --- Node 2: Publisher Assembler ---
        $blueprintPath = (\defined('MUA_PUB_DIR') ? \rtrim((string) MUA_PUB_DIR, '/') : '') . '/assets/blueprints/mobile-shell';
        $checks[] = [
            'label'  => __('Mobile shell blueprint present', 'mustuse-apps-pub'),
            'status' => \is_dir($blueprintPath) ? 'pass' : 'fail',
            'node'   => 2,
        ];

        $blocksWithMobile = \glob(
            (\defined('MUA_PUB_DIR') ? \rtrim((string) MUA_PUB_DIR, '/') : '') . '/src/Blocks/*/mobile.blade.php'
        ) ?: [];
        $checks[] = [
            'label'  => \sprintf(
                /* translators: %d: number of blocks with mobile templates */
                __('%d block(s) with mobile.blade.php', 'mustuse-apps-pub'),
                \count($blocksWithMobile)
            ),
            'status' => \count($blocksWithMobile) > 0 ? 'pass' : 'fail',
            'node'   => 2,
        ];

        // --- Node 3: GitHub (Projection) ---
        $repoUrl = (string) $app->meta('build_repo_url', '');
        $checks[] = [
            'label'  => __('Build repository URL configured', 'mustuse-apps-pub'),
            'status' => $repoUrl !== '' ? 'pass' : 'fail',
            'node'   => 3,
        ];

        $tokenStored = (string) $app->meta('github_token', '');
        $checks[] = [
            'label'  => __('GitHub token stored (encrypted)', 'mustuse-apps-pub'),
            'status' => $tokenStored !== '' ? 'pass' : 'fail',
            'node'   => 3,
        ];

        // --- Node 4: Bifrost (manual) ---
        $checks[] = [
            'label'  => __('Bifrost project must be configured manually', 'mustuse-apps-pub'),
            'status' => 'info',
            'node'   => 4,
        ];

        // --- Node 5: Device (educational) ---
        $checks[] = [
            'label'  => __('Shell boots manifest from storage, verifies HMAC, renders screens', 'mustuse-apps-pub'),
            'status' => 'info',
            'node'   => 5,
        ];

        /**
         * Filter: extend the per-app deployment requirements for the Deployment Map.
         *
         * Third-party extensions may add their own checks (e.g. "APNs cert uploaded")
         * to any node by appending to the array.
         *
         * @param list<array{label: string, status: 'pass'|'fail'|'info', node: int}> $checks
         * @param App $app
         */
        return (array) apply_filters('mua_app_requirements_check', $checks, $app);
    }
}
