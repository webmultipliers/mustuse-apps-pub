<?php

declare(strict_types=1);

namespace MustUse\Pub\Routing;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;

/**
 * Single source of truth for an app's routing + navigation.
 *
 * Output:
 *   - screens:     flat list, every published screen, each with `path`
 *   - routing:     flat list, screens with a content source
 *   - navigation:  flat list, screens flagged show_in_nav
 *   - screen_tree: hierarchical view of the same screens for drawer nav
 *
 * Path rules (1:1 with `screen_id`, no drift between sides):
 *   - the home screen → `/`
 *   - detail screens with a `_mua_deeplink_path` → that template
 *   - everything else → `/{slug}`
 *
 * Home election: explicit `_mua_is_home` wins; otherwise the first
 * show_in_nav screen by nav_order; otherwise the first published screen.
 */
final class ScreenRouteProjector
{
    public static function projectFor(App $app): array
    {
        $screens = Screen::findByApp($app->id(), [
            'post_status' => 'publish',
        ]);

        $homeId       = self::electHomeScreenId($screens);
        $screenRows   = [];
        $routing      = [];
        $navItems     = [];
        $sideNavItems = [];
        $byParent     = [];
        $pathBySlug   = [];

        foreach ($screens as $screen) {
            $slug = $screen->slug();
            if ($slug === '') {
                continue;
            }

            $role         = $screen->role();
            $routeType    = $screen->routeType();
            $queryArgs    = $screen->queryArgs($app);
            $deeplinkPath = $screen->deeplinkPath();
            $path         = self::resolvePath($screen, $homeId, $deeplinkPath);

            $pathBySlug[$slug] = $path;

            $route = [
                'role'          => $role,
                'deeplink_path' => $deeplinkPath,
                'type'          => $routeType,
                'post_type'     => $screen->routePostType(),
                'taxonomy'      => $screen->routeTaxonomy(),
                'term_ids'      => $screen->routeTermIds(),
                'custom_id'     => $screen->routeCustomId(),
                'meta_query'    => $screen->routeMetaQuery(),
                'orderby'       => $screen->routeOrderby(),
                'order'         => $screen->routeOrder(),
                'query_args'    => $queryArgs,
            ];

            $screenRows[] = [
                'screen_id'        => $slug,
                'path'             => $path,
                'title'            => $screen->title(),
                'icon'             => $screen->icon(),
                'route'            => $route,
                'show_in_nav'      => $screen->showInNav(),
                'nav_order'        => $screen->navOrder(),
                'show_in_side_nav' => $screen->showInSideNav(),
                'side_nav_order'   => $screen->sideNavOrder(),
                'is_home'          => $screen->id() === $homeId,
            ];

            if ($role === 'archive' && $routeType !== 'none') {
                $routing[] = [
                    'screen_id'  => $slug,
                    'role'       => 'archive',
                    'type'       => $routeType,
                    'post_type'  => $screen->routePostType(),
                    'taxonomy'   => $screen->routeTaxonomy(),
                    'term_ids'   => $screen->routeTermIds(),
                    'custom_id'  => $screen->routeCustomId(),
                    'meta_query' => $screen->routeMetaQuery(),
                    'orderby'    => $screen->routeOrderby(),
                    'order'      => $screen->routeOrder(),
                    'query_args' => $queryArgs,
                ];
            } elseif ($role === 'detail' && $deeplinkPath !== '') {
                $routing[] = [
                    'screen_id'     => $slug,
                    'role'          => 'detail',
                    'deeplink_path' => $deeplinkPath,
                ];
            }

            if ($screen->showInNav()) {
                $navItems[] = [
                    'screen_id' => $slug,
                    'path'      => $path,
                    'label'     => $screen->title(),
                    'icon'      => $screen->icon(),
                    'order'     => $screen->navOrder(),
                ];
            }

            if ($screen->showInSideNav()) {
                $sideNavItems[] = [
                    'screen_id' => $slug,
                    'path'      => $path,
                    'label'     => $screen->title(),
                    'icon'      => $screen->icon(),
                    'order'     => $screen->sideNavOrder(),
                ];
            }

            $byParent[$screen->parentId()][] = $screen;
        }

        \usort($navItems,     static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        \usort($sideNavItems, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return [
            'screens'     => $screenRows,
            'routing'     => $routing,
            'navigation'  => $navItems,
            'side_nav'    => $sideNavItems,
            'screen_tree' => self::buildTree($byParent, 0, $pathBySlug),
        ];
    }

    /**
     * @param Screen[] $screens
     */
    private static function electHomeScreenId(array $screens): int
    {
        foreach ($screens as $screen) {
            if ($screen->isHome() && $screen->slug() !== '') {
                return $screen->id();
            }
        }

        $candidates = \array_values(\array_filter(
            $screens,
            static fn (Screen $s): bool => $s->showInNav() && $s->slug() !== ''
        ));
        \usort($candidates, static fn (Screen $a, Screen $b): int => $a->navOrder() <=> $b->navOrder());
        if ($candidates !== []) {
            return $candidates[0]->id();
        }

        foreach ($screens as $screen) {
            if ($screen->slug() !== '') {
                return $screen->id();
            }
        }

        return 0;
    }

    private static function resolvePath(Screen $screen, int $homeId, string $deeplinkPath): string
    {
        if ($screen->id() === $homeId) {
            return '/';
        }

        if ($screen->role() === 'detail' && $deeplinkPath !== '') {
            return $deeplinkPath;
        }

        return '/' . $screen->slug();
    }

    /**
     * @param array<int, list<Screen>>   $byParent
     * @param array<string, string>      $pathBySlug
     * @return list<array{screen_id: string, path: string, title: string, icon: string, children: array}>
     */
    private static function buildTree(array $byParent, int $parentId, array $pathBySlug): array
    {
        $children = $byParent[$parentId] ?? [];
        $nodes    = [];

        foreach ($children as $screen) {
            $slug = $screen->slug();
            if ($slug === '') {
                continue;
            }

            $nodes[] = [
                'screen_id' => $slug,
                'path'      => $pathBySlug[$slug] ?? '/' . $slug,
                'title'     => $screen->title(),
                'icon'      => $screen->icon(),
                'children'  => self::buildTree($byParent, $screen->id(), $pathBySlug),
            ];
        }

        return $nodes;
    }
}
