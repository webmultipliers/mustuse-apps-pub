<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Shell;

use MustUse\Pub\Api\Middleware\AppKeyAuth;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /apps/{app_slug}/menus/{location}
 *
 * Serves a WordPress-registered nav menu as a flat, manifest-friendly
 * tree so the shell's wp-menu block can render theme-driven navigation
 * without re-authoring it in the admin. `{location}` matches
 * `get_registered_nav_menus()` keys.
 *
 * Same AppKey-auth contract as the other shell routes. TTL defaults to
 * 30 minutes (menus rarely move); override via `mua_menu_cache_ttl`.
 */
final class MenuRoute
{
    private const NAMESPACE   = 'mustuse-apps-pub/v1';
    private const DEFAULT_TTL = 1800;

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_slug>[a-z0-9-]+)/menus/(?P<location>[a-z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [AppKeyAuth::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $location = (string) $request->get_param('location');

        $menuId = 0;
        if (\function_exists('get_nav_menu_locations')) {
            $locations = get_nav_menu_locations();
            $menuId    = \is_array($locations) && isset($locations[$location]) ? (int) $locations[$location] : 0;
        }
        if ($menuId === 0) {
            return new WP_REST_Response([
                'location' => $location,
                'items'    => [],
            ], 200);
        }

        $fetched = \function_exists('wp_get_nav_menu_items') ? wp_get_nav_menu_items($menuId) : [];
        $rawItems = \is_array($fetched)
            ? \array_values(\array_filter($fetched, static fn ($i): bool => $i instanceof WP_Post))
            : [];
        $items = self::buildTree($rawItems);

        $ttl = (int) apply_filters('mua_menu_cache_ttl', self::DEFAULT_TTL);

        return new WP_REST_Response([
            'location' => $location,
            'items'    => $items,
        ], 200, [
            'Cache-Control' => 'public, max-age=' . \max(0, $ttl),
        ]);
    }

    /**
     * WP's nav_menu_items are `WP_Post` objects with dynamic menu
     * properties (`menu_item_parent`, `object_id`, `classes` …) set by
     * `wp_setup_nav_menu_item()`. Nest them into a tree so the wp-menu
     * block renders without a second pass.
     *
     * @param list<WP_Post> $rawItems
     * @return list<array<string, mixed>>
     */
    private static function buildTree(array $rawItems): array
    {
        /** @var array<int, list<WP_Post>> $byParent */
        $byParent = [];
        foreach ($rawItems as $item) {
            $parentId = (int) ($item->menu_item_parent ?? 0);
            $byParent[$parentId][] = $item;
        }

        $build = static function (int $parentId) use (&$byParent, &$build): array {
            $out = [];
            /** @var list<WP_Post> $items */
            $items = $byParent[$parentId] ?? [];
            foreach ($items as $item) {
                $classes = $item->classes ?? [];
                $out[] = [
                    'id'         => (int) $item->ID,
                    'title'      => (string) ($item->title ?? ''),
                    'url'        => (string) ($item->url ?? ''),
                    'type'       => (string) ($item->type ?? ''),
                    'object'     => (string) ($item->object ?? ''),
                    'object_id'  => (int) ($item->object_id ?? 0),
                    'classes'    => \is_array($classes) ? \array_values(\array_filter($classes, 'is_string')) : [],
                    'children'   => $build((int) $item->ID),
                ];
            }
            return $out;
        };

        return $build(0);
    }
}
