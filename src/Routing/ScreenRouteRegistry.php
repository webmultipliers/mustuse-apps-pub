<?php

declare(strict_types=1);

namespace MustUse\Pub\Routing;

use MustUse\Pub\Data\Models\App;

/**
 * Custom Screen Route Registry.
 *
 * Third-party developers register PHP callables that produce WP_Query-style
 * args for a screen's content source, keyed by a stable ID. The admin UI
 * only lists registered IDs — it never accepts arbitrary PHP — so the
 * security boundary stays on the code side.
 *
 * Mirrors ExtensionRegistry's shape. Intended bootstrap:
 *
 *   register_screen_route('latest-premium', 'Latest premium posts',
 *       fn (App $app) => ['post_type' => 'post', 'meta_key' => '_premium']);
 *
 * Call from a plugin/theme on the `mua_register_screen_routes` action.
 */
final class ScreenRouteRegistry
{
    /** @var array<string, array{id: string, label: string, provider: callable}> */
    private static array $routes = [];

    private static bool $initialized = false;

    /**
     * Register a custom screen route.
     *
     * Silently no-ops on duplicate IDs (first registration wins) so reloading
     * a plugin during development doesn't error.
     */
    public static function register(string $id, string $label, callable $provider): void
    {
        $key = sanitize_key($id);
        if ($key === '') {
            return;
        }
        if (isset(self::$routes[$key])) {
            return;
        }
        self::$routes[$key] = [
            'id'       => $key,
            'label'    => sanitize_text_field($label),
            'provider' => $provider,
        ];
    }

    /**
     * Returns all registered routes, keyed by ID.
     *
     * @return array<string, array{id: string, label: string, provider: callable}>
     */
    public static function all(): array
    {
        self::ensureInitialized();
        return self::$routes;
    }

    /**
     * Resolve a route ID to WP_Query args for the given app. Returns null
     * if the route isn't registered (e.g. the plugin that registered it is
     * disabled) or the provider throws — callers must fail-soft and render
     * the screen as static.
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(string $id, App $app): ?array
    {
        self::ensureInitialized();
        $key = sanitize_key($id);
        if ($key === '' || ! isset(self::$routes[$key])) {
            return null;
        }
        try {
            $args = (self::$routes[$key]['provider'])($app);
            return \is_array($args) ? $args : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Testing seam — clears the registry. Never call from production code.
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$initialized = false;
    }

    private static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        /** @see mua_register_screen_routes action — dev registration entry point. */
        do_action('mua_register_screen_routes');
    }
}
