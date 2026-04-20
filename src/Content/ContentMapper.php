<?php

declare(strict_types=1);

namespace MustUse\Pub\Content;

use MustUse\Pub\Data\Models\App;
use WP_Post;
use WP_Query;

/**
 * Maps WordPress content (posts, pages, custom post types) to the
 * JSON shape consumed by shells at /apps/{slug}/content.
 *
 * Per-app scope:
 *   - Post types advertised via the `mua_content_post_types` filter
 *     (optionally per-app routing meta) are considered queryable.
 *   - If a post type carries the `_mua_app_id` meta, queries for that
 *     type are restricted to posts whose `_mua_app_id` equals the
 *     current app's ID. Public / non-app-scoped types are returned
 *     without that restriction.
 */
final class ContentMapper
{
    private const DEFAULT_POST_TYPES = ['post', 'page'];
    private const DEFAULT_PER_PAGE   = 20;
    private const MAX_PER_PAGE       = 100;
    private const MAX_EXCLUDE_IDS    = 50;
    private const MAX_SEARCH_LENGTH  = 200;

    /**
     * Columns we let the shell sort on. Intentionally narrow — arbitrary
     * `orderby` values against WP_Query open us up to slow queries and
     * inconsistent behavior across extensions. Extensions can add entries
     * via the `mua_content_allowed_orderby` filter.
     */
    private const ALLOWED_ORDERBY = ['date', 'title', 'menu_order', 'modified', 'rand', 'comment_count'];

    /**
     * @param array<string,mixed> $params Query parameters from the REST request.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int},
     *     app: array{id: int, slug: string}
     * }
     */
    public function mapForApp(?App $app, array $params): array
    {
        if (! $app instanceof App) {
            return [
                'items'      => [],
                'pagination' => ['page' => 1, 'per_page' => 0, 'total' => 0, 'total_pages' => 0],
                'app'        => ['id' => 0, 'slug' => ''],
            ];
        }

        $allowedTypes = $this->allowedPostTypes($app);
        $requested    = isset($params['post_type']) ? (string) $params['post_type'] : 'post';

        // `any`, `global`, or a csv (`post,page`) opts into multi-type search.
        // WP_Query accepts an array for `post_type`; anything outside our
        // allowlist is filtered out so publishers can't expose private CPTs
        // by accident.
        $isMulti  = false;
        $postType = $allowedTypes[0];
        if ($requested === 'any' || $requested === 'global') {
            $postType = $allowedTypes;
            $isMulti  = true;
        } elseif (\str_contains($requested, ',')) {
            $requestedList = \array_values(\array_intersect(
                \array_map('trim', \explode(',', $requested)),
                $allowedTypes
            ));
            if ($requestedList !== []) {
                $postType = \count($requestedList) === 1 ? $requestedList[0] : $requestedList;
                $isMulti  = \count($requestedList) > 1;
            }
        } elseif (\in_array($requested, $allowedTypes, true)) {
            $postType = $requested;
        }

        // Single-post lookup.
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id > 0) {
            $post = get_post($id);
            if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
                return $this->emptyResponse($app);
            }
            if (! $this->postBelongsToApp($post, $app)) {
                return $this->emptyResponse($app);
            }

            return [
                // Single-id lookup returns the full post shape (content + meta);
                // list queries use minimal mode via serializePost($post) below.
                'items'      => [$this->serializePost($post, false)],
                'pagination' => ['page' => 1, 'per_page' => 1, 'total' => 1, 'total_pages' => 1],
                'app'        => ['id' => $app->id(), 'slug' => $app->slug()],
            ];
        }

        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : self::DEFAULT_PER_PAGE;
        $perPage = \max(1, \min(self::MAX_PER_PAGE, $perPage));
        $page    = isset($params['page']) ? \max(1, (int) $params['page']) : 1;

        $queryArgs = [
            'post_type'      => $postType,
            'post_status'    => 'publish',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => $this->resolveOrderBy($params),
            'order'          => $this->resolveOrder($params),
        ];

        if (! empty($params['search'])) {
            $queryArgs['s'] = \mb_substr((string) $params['search'], 0, self::MAX_SEARCH_LENGTH);
        }

        // Author scope — integer user id; 0/absent means "any author".
        $author = isset($params['author']) ? (int) $params['author'] : 0;
        if ($author > 0) {
            $queryArgs['author'] = $author;
        }

        // `exclude` takes a csv of post IDs (or a single id) and maps
        // directly to WP_Query's `post__not_in`. Used by related-articles
        // to omit the current post from its own "related" list. Capped so
        // a shell can't wedge the query with a megabyte-long csv.
        if (! empty($params['exclude'])) {
            $excludeIds = \array_values(\array_filter(\array_map(
                'intval',
                \is_array($params['exclude']) ? $params['exclude'] : \explode(',', (string) $params['exclude'])
            )));
            if ($excludeIds !== []) {
                $queryArgs['post__not_in'] = \array_slice($excludeIds, 0, self::MAX_EXCLUDE_IDS);
            }
        }

        // Taxonomy scope: either `category` (shorthand for `category_name` by id)
        // or the general `taxonomy`+`term` pair. `term` accepts slugs or ids;
        // we pass both and let WP_Query resolve.
        $category = isset($params['category']) ? (int) $params['category'] : 0;
        $taxonomy = isset($params['taxonomy']) ? (string) $params['taxonomy'] : '';
        $term     = isset($params['term']) ? (string) $params['term'] : '';
        $taxQuery = $this->buildTaxQuery($category, $taxonomy, $term);
        if ($taxQuery !== []) {
            $queryArgs['tax_query'] = $taxQuery;
        }

        // App-scoping only applies to single-type queries; global/multi
        // searches don't attempt to restrict by _mua_app_id because every
        // type would need its own scope. Extensions that need that for a
        // multi-type search should hook `mua_content_query`.
        if (! $isMulti && \is_string($postType) && $this->postTypeIsAppScoped($postType)) {
            $queryArgs['meta_query'] = [
                [
                    'key'     => '_mua_app_id',
                    'value'   => $app->id(),
                    'compare' => '=',
                ],
            ];
        }

        /** @see mua_content_query filter — allow extensions to refine the query */
        $queryArgs = apply_filters('mua_content_query', $queryArgs, $app, $params);

        $query = new WP_Query($queryArgs);
        // List queries → minimal post shape (no content body, no meta) so
        // a 10-item response stays compact. Context blocks on loop cards
        // still get title / excerpt / date / featured_image / author / terms.
        $items = [];
        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $items[] = $this->serializePost($post, true);
            }
        }

        return [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
            'app'        => ['id' => $app->id(), 'slug' => $app->slug()],
        ];
    }

    /**
     * @return string[]
     */
    private function allowedPostTypes(App $app): array
    {
        $types = $app->meta('content_post_types', self::DEFAULT_POST_TYPES);
        if (! \is_array($types) || empty($types)) {
            $types = self::DEFAULT_POST_TYPES;
        }

        /** @see mua_content_post_types filter — per-app allowed CPTs */
        $filtered = apply_filters('mua_content_post_types', $types, $app);

        return \array_values(\array_filter(\array_map('strval', (array) $filtered), 'post_type_exists'));
    }

    private function postTypeIsAppScoped(string $postType): bool
    {
        /** @see mua_post_type_app_scoped filter — mark CPTs that carry `_mua_app_id` */
        return (bool) apply_filters('mua_post_type_app_scoped', false, $postType);
    }

    private function postBelongsToApp(WP_Post $post, App $app): bool
    {
        if (! $this->postTypeIsAppScoped($post->post_type)) {
            return true;
        }
        $postAppId = (int) get_post_meta($post->ID, '_mua_app_id', true);
        return $postAppId === $app->id();
    }

    /**
     * Serialize a post for the /content response.
     *
     * Delegates to `ContextBuilder::postContext()` so `/content` items,
     * detail-screen contexts, and QueryLoop iterations all share one
     * shape. `$minimal=true` (used for list endpoints) skips the expensive
     * content body + registered meta so a 10-item payload stays small;
     * `$minimal=false` (single-id lookup, detail screens) returns the full
     * shape.
     *
     * @return array<string, mixed>
     */
    private function serializePost(WP_Post $post, bool $minimal = true): array
    {
        $serialized = ContextBuilder::postContext($post, $minimal);
        if (! \is_array($serialized)) {
            $serialized = [
                'id'        => $post->ID,
                'slug'      => $post->post_name,
                'post_type' => $post->post_type,
                'title'     => get_the_title($post),
            ];
        }

        // Keep the legacy `permalink` field separate from the canonical
        // in-app `url` because they resolve to different places (WP domain
        // vs. app route). Consumers that need one or the other can pick.
        $serialized['permalink'] = get_permalink($post);

        /** @see mua_content_item filter — let extensions enrich serialized posts */
        return apply_filters('mua_content_item', $serialized, $post);
    }

    /**
     * @return array{
     *     items: array<int, array<string,mixed>>,
     *     pagination: array{page:int, per_page:int, total:int, total_pages:int},
     *     app: array{id:int, slug:string}
     * }
     */
    private function emptyResponse(App $app): array
    {
        return [
            'items'      => [],
            'pagination' => ['page' => 1, 'per_page' => 0, 'total' => 0, 'total_pages' => 0],
            'app'        => ['id' => $app->id(), 'slug' => $app->slug()],
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function resolveOrderBy(array $params): string
    {
        $requested = isset($params['orderby']) ? (string) $params['orderby'] : 'date';
        /** @see mua_content_allowed_orderby — extensions can add allowed columns */
        $allowed = (array) apply_filters('mua_content_allowed_orderby', self::ALLOWED_ORDERBY);
        return \in_array($requested, $allowed, true) ? $requested : 'date';
    }

    /**
     * @param array<string,mixed> $params
     */
    private function resolveOrder(array $params): string
    {
        $requested = isset($params['order']) ? \strtoupper((string) $params['order']) : 'DESC';
        return $requested === 'ASC' ? 'ASC' : 'DESC';
    }

    /**
     * Build a tax_query from either the shorthand `category` (integer id)
     * or the general `taxonomy`+`term` pair. Returns [] when nothing is
     * specified so the caller can skip adding the key.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTaxQuery(int $category, string $taxonomy, string $term): array
    {
        if ($category > 0) {
            return [[
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => [$category],
            ]];
        }
        if ($taxonomy !== '' && $term !== '') {
            // Numeric term → id; anything else → slug. Simpler than asking
            // the shell to tell us which.
            $field = \ctype_digit($term) ? 'term_id' : 'slug';
            $value = $field === 'term_id' ? [(int) $term] : [$term];
            return [[
                'taxonomy' => $taxonomy,
                'field'    => $field,
                'terms'    => $value,
            ]];
        }
        return [];
    }
}
