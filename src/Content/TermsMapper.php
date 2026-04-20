<?php

declare(strict_types=1);

namespace MustUse\Pub\Content;

use MustUse\Pub\Data\Models\App;

/**
 * Maps WordPress taxonomy terms to the JSON shape consumed by shells at
 * /apps/{slug}/terms. Split from ContentMapper because terms are a
 * different resource with different pagination semantics (term queries
 * don't paginate the same way WP_Query does) and the shape
 * expectations (`name`, `slug`, `count`, `url`) are narrower.
 *
 * Category-pills, navigation chips, and "archive" context panels all
 * consume this shape.
 */
final class TermsMapper
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE     = 200;

    /**
     * @param array<string,mixed> $params
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     pagination: array{per_page: int, total: int},
     *     app: array{id: int, slug: string}
     * }
     */
    public function mapForApp(?App $app, array $params): array
    {
        if (! $app instanceof App) {
            return [
                'items'      => [],
                'pagination' => ['per_page' => 0, 'total' => 0],
                'app'        => ['id' => 0, 'slug' => ''],
            ];
        }

        $taxonomy = isset($params['taxonomy']) ? (string) $params['taxonomy'] : 'category';
        if (! taxonomy_exists($taxonomy)) {
            $taxonomy = 'category';
        }

        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : self::DEFAULT_PER_PAGE;
        $perPage = \max(1, \min(self::MAX_PER_PAGE, $perPage));

        $orderBy = $this->resolveOrderBy($params);
        $order   = (isset($params['order']) && \strtoupper((string) $params['order']) === 'ASC') ? 'ASC' : 'DESC';

        $args = [
            'taxonomy'   => $taxonomy,
            'number'     => $perPage,
            'hide_empty' => ! empty($params['include_empty']) ? false : true,
            'orderby'    => $orderBy,
            'order'      => $order,
        ];

        // Optional include list (comma-separated ids) — typically used when
        // an editor hand-picks which terms render in a pill row.
        if (! empty($params['include'])) {
            $ids = \array_values(\array_filter(\array_map('intval', \explode(',', (string) $params['include']))));
            if ($ids !== []) {
                $args['include'] = $ids;
            }
        }

        /** @see mua_terms_query filter — extensions can refine the query */
        $args = apply_filters('mua_terms_query', $args, $app, $params);

        $terms = get_terms($args);
        if (is_wp_error($terms) || ! \is_array($terms)) {
            $terms = [];
        }

        $items = \array_map([$this, 'serializeTerm'], $terms);

        return [
            'items'      => $items,
            'pagination' => ['per_page' => $perPage, 'total' => \count($items)],
            'app'        => ['id' => $app->id(), 'slug' => $app->slug()],
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function resolveOrderBy(array $params): string
    {
        $allowed   = ['name', 'count', 'slug', 'term_id'];
        $requested = isset($params['orderby']) ? (string) $params['orderby'] : 'count';
        return \in_array($requested, $allowed, true) ? $requested : 'count';
    }

    /**
     * @param \WP_Term $term
     * @return array<string, mixed>
     */
    private function serializeTerm($term): array
    {
        $serialized = [
            'id'       => (int) $term->term_id,
            'name'     => (string) $term->name,
            'slug'     => (string) $term->slug,
            'taxonomy' => (string) $term->taxonomy,
            'count'    => (int) $term->count,
            'url'      => '/' . ($term->taxonomy === 'category' ? 'category' : $term->taxonomy) . '/' . $term->slug,
        ];

        /** @see mua_terms_item — extensions can enrich each term */
        return apply_filters('mua_terms_item', $serialized, $term);
    }
}
