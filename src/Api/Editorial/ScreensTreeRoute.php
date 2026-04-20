<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Editorial;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Editorial endpoints powering the "Screens & Navigation" metabox.
 *
 *   POST   /apps/{app_id}/screens                — create (optional parent_id)
 *   POST   /apps/{app_id}/screens/tree           — bulk reparent + reorder
 *   DELETE /apps/{app_id}/screens/{screen_id}    — recursive delete
 *
 * Auth: `edit_post` on the App. Tree mutations cascade through normal
 * `wp_update_post` / `wp_delete_post` so save_post observers
 * (PostLifecycle, manifest cache invalidation) all fire as usual.
 */
final class ScreensTreeRoute
{
    private const NAMESPACE     = 'mustuse-apps-pub/v1';
    private const MAX_TREE_ROWS = 500;

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/apps/(?P<app_id>\d+)/screens', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleCreate'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/apps/(?P<app_id>\d+)/screens/tree', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleUpdateTree'],
            'permission_callback' => [$this, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/apps/(?P<app_id>\d+)/screens/(?P<screen_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'handleDelete'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(WP_REST_Request $request): bool
    {
        $appId = (int) $request->get_param('app_id');
        if ($appId <= 0) {
            return false;
        }
        return current_user_can('edit_post', $appId);
    }

    public function handleCreate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $appId = (int) $request->get_param('app_id');
        $app   = App::find($appId);
        if (! $app instanceof App) {
            return new WP_Error('mua_app_not_found', __('App not found.', 'mustuse-apps-pub'), ['status' => 404]);
        }

        $title    = sanitize_text_field((string) ($request->get_param('title') ?? ''));
        $parentId = (int) ($request->get_param('parent_id') ?? 0);

        $screen = Screen::create($appId, $title, $parentId);
        if (! $screen instanceof Screen) {
            return new WP_Error('mua_screen_create_failed', __('Could not create screen.', 'mustuse-apps-pub'), ['status' => 500]);
        }

        return new WP_REST_Response([
            'screen_id' => $screen->id(),
            'title'     => $screen->title(),
            'slug'      => $screen->slug(),
            'parent_id' => $screen->parentId(),
            'edit_url'  => (string) get_edit_post_link($screen->id(), 'raw'),
        ], 201);
    }

    /**
     * Replace the app's tree position state. Payload:
     *   { "tree": [ { "id": 12, "parent": 0, "order": 0 }, ... ] }
     *
     * Only screens belonging to the named app are touched; unknown ids and
     * parent references that point outside the app are ignored. We don't
     * reject the whole payload on a single bad row because the client builds
     * the list from the live DOM and a stale id could otherwise cancel a
     * valid drag.
     */
    public function handleUpdateTree(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $appId = (int) $request->get_param('app_id');
        $app   = App::find($appId);
        if (! $app instanceof App) {
            return new WP_Error('mua_app_not_found', __('App not found.', 'mustuse-apps-pub'), ['status' => 404]);
        }

        $rows = $request->get_param('tree');
        if (! \is_array($rows)) {
            return new WP_Error('mua_invalid_tree', __('Tree payload must be an array.', 'mustuse-apps-pub'), ['status' => 400]);
        }
        if (\count($rows) > self::MAX_TREE_ROWS) {
            return new WP_Error('mua_tree_too_large', __('Tree payload exceeds the maximum row count.', 'mustuse-apps-pub'), ['status' => 400]);
        }

        // Build a lookup of valid screens for this app so each row can be
        // validated in O(1) without re-querying per-iteration.
        $owned = [];
        foreach (Screen::findByApp($appId, ['post_status' => 'any']) as $s) {
            $owned[$s->id()] = $s;
        }

        $updated = 0;

        foreach ($rows as $row) {
            if (! \is_array($row)) {
                continue;
            }
            $id     = (int) ($row['id'] ?? 0);
            $parent = (int) ($row['parent'] ?? 0);
            $order  = (int) ($row['order'] ?? 0);

            if (! isset($owned[$id])) {
                continue;
            }
            // Parent must also be owned by this app, or 0 (root). A row that
            // names itself as parent is silently demoted to root.
            if ($parent !== 0 && (! isset($owned[$parent]) || $parent === $id)) {
                $parent = 0;
            }

            wp_update_post([
                'ID'          => $id,
                'post_parent' => $parent,
                'menu_order'  => $order,
            ]);
            $updated++;
        }

        return new WP_REST_Response([
            'updated' => $updated,
        ], 200);
    }

    public function handleDelete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $appId    = (int) $request->get_param('app_id');
        $screenId = (int) $request->get_param('screen_id');

        $screen = Screen::find($screenId);
        if (! $screen instanceof Screen || $screen->appId() !== $appId) {
            return new WP_Error('mua_screen_not_found', __('Screen not found.', 'mustuse-apps-pub'), ['status' => 404]);
        }

        $deleted = self::deleteRecursive($screenId, $appId);

        return new WP_REST_Response(['deleted' => $deleted], 200);
    }

    /**
     * Recursive delete: any descendants whose post_parent chain leads back to
     * $rootId are removed. We avoid wp_delete_post's `force_delete=false`
     * because Screens have no Trash UX — they're always force-deleted.
     */
    private static function deleteRecursive(int $rootId, int $appId): int
    {
        $count = 0;

        $children = get_posts([
            'post_type'   => Screen::POST_TYPE,
            'post_parent' => $rootId,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        ]);

        foreach ($children as $childId) {
            $childId = (int) $childId;
            $child   = Screen::find($childId);
            if ($child instanceof Screen && $child->appId() === $appId) {
                $count += self::deleteRecursive($childId, $appId);
            }
        }

        if (wp_delete_post($rootId, true)) {
            $count++;
        }

        return $count;
    }
}
