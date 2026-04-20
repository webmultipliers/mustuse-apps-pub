<?php

declare(strict_types=1);

namespace MustUse\Pub\Preview;

use MustUse\Pub\Admin\DeploymentRequirements;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use MustUse\Pub\Manifest\ManifestBuilder;
use MustUse\Pub\Routing\ScreenRouteProjector;
use MustUse\Pub\Support\Vite;
use WP_Post;

/**
 * Preview dashboard endpoint.
 *
 * Hooks `template_redirect` to catch `?mua_preview=<post_id>` requests on
 * the public-facing site and renders a self-contained dashboard template
 * (no theme chrome, just `wp_head`/`wp_footer` for admin bar + Vite
 * assets). Auth uses WP's standard preview-nonce convention so the
 * existing Gutenberg Preview button works after `preview_post_link` is
 * rewritten to point here.
 *
 * The dashboard renders identical chrome for both App and Screen
 * preview requests; the difference is purely what fills the device
 * frame (an app's nav-flagged top-level screens, or a single screen).
 */
final class PreviewRoute
{
    public const QUERY_VAR = 'mua_preview';

    public static function register(): void
    {
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            $vars[] = 'preview_nonce';
            return $vars;
        });

        add_filter('preview_post_link', [self::class, 'filterPreviewLink'], 10, 2);
        add_action('template_redirect', [self::class, 'maybeRender']);
    }

    /**
     * Rewrite Gutenberg's Preview button URL for App / Screen posts to
     * point at our dashboard instead of the (non-existent) public single
     * template.
     */
    public static function filterPreviewLink(string $url, WP_Post $post): string
    {
        if (! \in_array($post->post_type, [App::POST_TYPE, Screen::POST_TYPE], true)) {
            return $url;
        }

        return self::previewUrlFor($post->ID);
    }

    public static function previewUrlFor(int $postId): string
    {
        return add_query_arg(
            [
                self::QUERY_VAR  => $postId,
                'preview_nonce'  => wp_create_nonce('post_preview_' . $postId),
            ],
            home_url('/')
        );
    }

    public static function maybeRender(): void
    {
        $postId = (int) get_query_var(self::QUERY_VAR);
        if ($postId <= 0) {
            // Also accept the value from $_GET when query_vars hasn't been
            // wired (e.g. a request that bypassed parse_query). Saves
            // publishers from a confusing 404 if their permalinks haven't
            // flushed.
            $postId = isset($_GET[self::QUERY_VAR]) ? (int) $_GET[self::QUERY_VAR] : 0;
        }
        if ($postId <= 0) {
            return;
        }

        $post = get_post($postId);
        if (! $post instanceof WP_Post) {
            self::abort(__('Preview not available — post not found.', 'mustuse-apps-pub'), 404);
        }

        if (! \in_array($post->post_type, [App::POST_TYPE, Screen::POST_TYPE], true)) {
            // This endpoint is scoped to our post types only — refuse
            // anything else so we never render a stranger's content.
            return;
        }

        $nonce = isset($_GET['preview_nonce']) ? (string) $_GET['preview_nonce'] : '';
        if (! wp_verify_nonce($nonce, 'post_preview_' . $postId)) {
            self::abort(__('Preview link expired or invalid.', 'mustuse-apps-pub'), 403);
        }

        if (! current_user_can('edit_post', $postId)) {
            self::abort(__('You do not have permission to preview this post.', 'mustuse-apps-pub'), 403);
        }

        // Honor autosaves the way WP's `_show_post_preview` would — but
        // for our own URL pattern. Shallow merge: title + content only,
        // matching `_set_preview()`'s contract.
        $autosave = wp_get_post_autosave($postId);
        if ($autosave instanceof WP_Post) {
            $post->post_content = $autosave->post_content;
            $post->post_title   = $autosave->post_title;
            $post->post_excerpt = $autosave->post_excerpt;
        }

        $context = self::buildContext($post, $autosave instanceof WP_Post);

        // Enqueue Vite assets — wp_head() inside the template will print
        // the script + style tags. Theme styles are intentionally NOT
        // enqueued; the dashboard owns its visual identity.
        Vite::enqueue('preview');

        nocache_headers();
        status_header(200);

        require MUA_PUB_DIR . 'views/preview/dashboard.php';
        exit;
    }

    /**
     * Build the data bag the dashboard template renders against.
     * Returned shape stays flat-ish so the view can be a thin printer.
     *
     * @return array{
     *   mode: 'app'|'screen',
     *   post: WP_Post,
     *   app: App,
     *   active_screen_id: ?int,
     *   panes: list<array{id: int, slug: string, title: string, html: string}>,
     *   navigation: list<array{id: int, slug: string, title: string, icon: string}>,
     *   branding: array<string, mixed>,
     *   bundle_id: string,
     *   version_name: string,
     *   capabilities: list<array{label: string, key: string, status: 'on'|'off'|'info', value: string}>,
     *   manifest_summary: list<array{key: string, value: string, highlight: bool}>,
     *   checklist: list<array{label: string, status: string, node: int}>,
     *   is_autosave: bool,
     *   editor_url: string,
     *   splash_image: string,
     * }
     */
    private static function buildContext(WP_Post $post, bool $isAutosave): array
    {
        $isApp = $post->post_type === App::POST_TYPE;

        $app = $isApp
            ? App::find($post->ID)
            : self::ownerAppFor($post->ID);

        if (! $app instanceof App) {
            self::abort(__('Preview not available — owning app not found.', 'mustuse-apps-pub'), 404);
        }

        $branding = self::brandingFor($app);
        $projection = ScreenRouteProjector::projectFor($app);

        // Build the device-frame panes. App preview emits one pane per
        // nav-flagged top-level screen; screen preview emits exactly one
        // (the previewed screen). In both cases the pane HTML is the
        // result of do_blocks() so blocks render the same way the
        // editor canvas already showed.
        if ($isApp) {
            $panes = self::buildAppPanes($app);
            $activeScreenId = $panes[0]['id'] ?? null;
        } else {
            $panes = self::buildScreenPanes($post);
            $activeScreenId = $post->ID;
        }

        $manifest = (new ManifestBuilder())->build($app);

        return [
            'mode'              => $isApp ? 'app' : 'screen',
            'post'              => $post,
            'app'               => $app,
            'active_screen_id'  => $activeScreenId,
            'panes'             => $panes,
            'navigation'        => self::buildNavigation($app, $projection['navigation']),
            'branding'          => $branding,
            'bundle_id'         => 'com.mustuse.' . $app->slug(),
            'version_name'      => (string) $app->meta('app_version_name', '1.0.0'),
            'capabilities'      => self::buildCapabilitiesPanel($app, $manifest),
            'manifest_summary'  => self::buildManifestSummary($manifest),
            'checklist'         => DeploymentRequirements::checksForApp($app),
            'is_autosave'       => $isAutosave,
            'editor_url'        => (string) get_edit_post_link($post->ID, 'raw'),
            'splash_image'      => (string) ($branding['splash_url'] ?? ''),
        ];
    }

    private static function ownerAppFor(int $screenId): ?App
    {
        $appId = (int) get_post_meta($screenId, '_mua_app_id', true);
        return $appId > 0 ? App::find($appId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function brandingFor(App $app): array
    {
        $defaults  = get_option('mua_publisher_branding', []);
        $overrides = $app->meta('branding', []);
        $merged    = \array_merge(
            \is_array($defaults) ? $defaults : [],
            \is_array($overrides) ? $overrides : []
        );

        $merged['primary_color']    ??= '#1d4ed8';
        $merged['accent_color']     ??= '#2271b1';
        $merged['background_color'] ??= '#ffffff';

        return $merged;
    }

    /**
     * @return list<array{id: int, slug: string, title: string, html: string}>
     */
    private static function buildAppPanes(App $app): array
    {
        // Top-level screens (no parent) ordered by menu_order — these are
        // the screens reachable directly from the bottom nav.
        $screens = Screen::findByApp($app->id(), [
            'post_status' => 'publish',
            'post_parent' => 0,
        ]);

        $panes = [];
        foreach ($screens as $screen) {
            $panes[] = self::paneFor($screen->id());
        }
        return $panes;
    }

    /**
     * @return list<array{id: int, slug: string, title: string, html: string}>
     */
    private static function buildScreenPanes(WP_Post $screenPost): array
    {
        return [self::paneFor($screenPost->ID, $screenPost)];
    }

    /**
     * @return array{id: int, slug: string, title: string, html: string}
     */
    private static function paneFor(int $screenId, ?WP_Post $hydratedPost = null): array
    {
        $post = $hydratedPost ?? get_post($screenId);
        if (! $post instanceof WP_Post) {
            return ['id' => $screenId, 'slug' => '', 'title' => '', 'html' => ''];
        }

        return [
            'id'    => $post->ID,
            'slug'  => $post->post_name !== '' ? $post->post_name : 'screen-' . $post->ID,
            'title' => $post->post_title !== '' ? $post->post_title : __('Untitled', 'mustuse-apps-pub'),
            'html'  => (string) apply_filters('the_content', $post->post_content),
        ];
    }

    /**
     * @param list<array{screen_id: string, label: string, icon: string, order: int}> $navItems
     * @return list<array{id: int, slug: string, title: string, icon: string}>
     */
    private static function buildNavigation(App $app, array $navItems): array
    {
        $nav = [];
        foreach ($navItems as $item) {
            $screen = Screen::findByApp($app->id(), [
                'post_status' => 'publish',
                'name'        => $item['screen_id'],
                'numberposts' => 1,
            ]);
            if (empty($screen[0])) {
                continue;
            }
            $nav[] = [
                'id'    => $screen[0]->id(),
                'slug'  => $item['screen_id'],
                'title' => $item['label'],
                'icon'  => $item['icon'] !== '' ? $item['icon'] : '•',
            ];
        }
        return $nav;
    }

    /**
     * Capabilities panel — focused on what's actually exercised by the
     * publisher's screens (`requiredCapabilities` from the manifest)
     * plus deeplink status. Shell-runtime advertisement (which the
     * publisher may not have populated yet) is not the source of truth
     * here; the goal is "does this app's content rely on capability X."
     *
     * @param array<string, mixed> $manifest
     * @return list<array{label: string, key: string, status: 'on'|'off'|'info', value: string}>
     */
    private static function buildCapabilitiesPanel(App $app, array $manifest): array
    {
        $required = \is_array($manifest['requiredCapabilities'] ?? null)
            ? $manifest['requiredCapabilities']
            : [];

        $labels = [
            'browser_inapp'  => __('Browser (in-app)', 'mustuse-apps-pub'),
            'browser_system' => __('Browser (system)', 'mustuse-apps-pub'),
            'browser_auth'   => __('Browser (auth)', 'mustuse-apps-pub'),
            'camera'         => __('Camera', 'mustuse-apps-pub'),
            'scanner'        => __('Scanner', 'mustuse-apps-pub'),
            'share'          => __('Share', 'mustuse-apps-pub'),
            'biometrics'     => __('Biometrics', 'mustuse-apps-pub'),
        ];

        $panel = [];
        foreach ($labels as $cap => $label) {
            $advertised = \in_array($cap, $required, true);
            $panel[] = [
                'label'  => $label,
                'key'    => $cap,
                'status' => $advertised ? 'on' : 'off',
                'value'  => $advertised
                    ? __('In use', 'mustuse-apps-pub')
                    : __('Not used', 'mustuse-apps-pub'),
            ];
        }

        $deepLinkScheme = (string) $app->meta('deeplink_scheme', '');
        $panel[] = [
            'label'  => __('Deep linking', 'mustuse-apps-pub'),
            'key'    => 'deeplink',
            'status' => $deepLinkScheme !== '' ? 'on' : 'off',
            'value'  => $deepLinkScheme !== '' ? $deepLinkScheme . '://' : __('Not set', 'mustuse-apps-pub'),
        ];

        return $panel;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array{key: string, value: string, highlight: bool}>
     */
    private static function buildManifestSummary(array $manifest): array
    {
        $screens    = \is_array($manifest['screens'] ?? null) ? $manifest['screens'] : [];
        $endpoints  = \is_array($manifest['endpoints'] ?? null) ? $manifest['endpoints'] : [];
        $renderers  = \is_array($manifest['renderers'] ?? null) ? $manifest['renderers'] : [];
        $extensions = \is_array($manifest['extensions'] ?? null) ? $manifest['extensions'] : [];

        return [
            ['key' => 'Manifest version', 'value' => (string) ($manifest['version'] ?? '1'), 'highlight' => true],
            ['key' => 'Screens',          'value' => (string) \count($screens),              'highlight' => false],
            ['key' => 'Endpoints',        'value' => (string) \count($endpoints),            'highlight' => false],
            ['key' => 'Renderers',        'value' => (string) \count($renderers),            'highlight' => false],
            ['key' => 'Extensions',       'value' => (string) \count($extensions),           'highlight' => false],
            ['key' => 'Cache (manifest)', 'value' => (string) ($manifest['cache_invalidations']['manifest'] ?? '0'), 'highlight' => false],
        ];
    }

    /**
     * @return never
     */
    private static function abort(string $message, int $status): void
    {
        wp_die(esc_html($message), '', ['response' => $status]);
    }
}
