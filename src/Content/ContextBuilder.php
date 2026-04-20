<?php

declare(strict_types=1);

namespace MustUse\Pub\Content;

use WP_Post;
use WP_Term;
use WP_User;

/**
 * Canonical post / term / author serializer for the mobile shell.
 *
 * One shape regardless of origin (DeeplinkResolver for detail URLs,
 * QueryLoop for iteration, ContentMapper for list responses) so a
 * `post-title` block reads `context.post.title` in every scenario.
 *
 * Shape contract:
 *   post:   { id, post_type, title, slug, url, content, excerpt,
 *             date, date_iso, date_human, modified,
 *             author: { id, name, slug, bio, url, avatar },
 *             featured_image: { id, url, alt, width, height } | null,
 *             terms: [ { id, name, slug, taxonomy, url } ],
 *             primary_category_id, primary_category_slug,
 *             meta: { <registered keys> }, reading_time_min }
 *   term:   { id, name, slug, taxonomy, count, description, url }
 *   author: { id, name, slug, bio, url, avatar }
 *
 * Extensions enrich each shape via `mua_context_<type>` filters.
 */
final class ContextBuilder
{
    /** Words per minute target for `reading_time_min`. */
    private const WPM = 225;

    /**
     * `$minimal=true` skips the full post body + registered meta so list
     * responses stay compact; detail screens use `$minimal=false`.
     *
     * @return array<string, mixed>|null
     */
    public static function postContext(?WP_Post $post, bool $minimal = false): ?array
    {
        if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
            return null;
        }

        $featuredId    = (int) get_post_thumbnail_id($post);
        $featuredImage = $featuredId > 0 ? ImageSerializer::fromAttachmentId($featuredId) : null;

        $authorId   = (int) $post->post_author;
        $authorData = $authorId > 0 ? self::authorContext(get_userdata($authorId)) : null;

        $terms           = self::resolveTerms($post);
        $primaryCategory = self::resolvePrimaryCategory($post);

        $content     = $minimal ? '' : (string) apply_filters('the_content', $post->post_content);
        $readingTime = $content !== '' ? self::estimateReadingTime($content) : 0;
        $excerptRaw  = get_the_excerpt($post);

        $context = [
            'id'                    => $post->ID,
            'post_type'             => $post->post_type,
            'title'                 => get_the_title($post),
            'slug'                  => $post->post_name,
            'url'                   => self::pathFor($post),
            'content'               => $content,
            'excerpt'               => wp_strip_all_tags((string) $excerptRaw),
            'date'                  => mysql2date('c', $post->post_date_gmt),
            'date_iso'              => mysql2date('c', $post->post_date_gmt),
            'date_human'            => self::dateHuman($post),
            'modified'              => mysql2date('c', $post->post_modified_gmt),
            'author'                => $authorData,
            'featured_image'        => $featuredImage,
            'terms'                 => $terms,
            'primary_category_id'   => $primaryCategory['id'],
            'primary_category_slug' => $primaryCategory['slug'],
            'meta'                  => $minimal ? [] : self::exposedMeta($post),
            'reading_time_min'      => $readingTime,
        ];

        /** @see mua_context_post — enrich the post context shape */
        return (array) apply_filters('mua_context_post', $context, $post, $minimal);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function authorContext(mixed $user): ?array
    {
        if (! $user instanceof WP_User) {
            return null;
        }

        $context = [
            'id'     => (int) $user->ID,
            'name'   => $user->display_name,
            'slug'   => $user->user_nicename,
            'bio'    => (string) get_user_meta($user->ID, 'description', true),
            'url'    => '/author/' . $user->user_nicename,
            'avatar' => [
                'id'     => 0,
                'url'    => get_avatar_url($user->ID, ['size' => 160]) ?: '',
                'alt'    => $user->display_name,
                'width'  => 160,
                'height' => 160,
            ],
        ];

        /** @see mua_context_author — enrich the author context shape */
        return (array) apply_filters('mua_context_author', $context, $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function termContext(mixed $term): ?array
    {
        if (! $term instanceof WP_Term) {
            return null;
        }

        $context = [
            'id'          => (int) $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'taxonomy'    => $term->taxonomy,
            'count'       => (int) $term->count,
            'description' => (string) $term->description,
            'url'         => '/' . ($term->taxonomy === 'category' ? 'category' : $term->taxonomy) . '/' . $term->slug,
        ];

        /** @see mua_context_term — enrich the term context shape */
        return (array) apply_filters('mua_context_term', $context, $term);
    }

    private static function pathFor(WP_Post $post): string
    {
        $default = $post->post_type === 'page'
            ? '/page/' . $post->post_name
            : '/article/' . $post->post_name;
        /** @see mua_context_post_url — override per post type or post */
        return (string) apply_filters('mua_context_post_url', $default, $post);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function resolveTerms(WP_Post $post): array
    {
        $taxonomies = get_object_taxonomies($post, 'names');
        $terms      = [];
        foreach ($taxonomies as $taxonomy) {
            $assigned = wp_get_post_terms($post->ID, $taxonomy);
            if (is_wp_error($assigned)) {
                continue;
            }
            foreach ($assigned as $term) {
                $mapped = self::termContext($term);
                if ($mapped !== null) {
                    $terms[] = $mapped;
                }
            }
        }
        return $terms;
    }

    /**
     * @return array{id: int, slug: string}
     */
    private static function resolvePrimaryCategory(WP_Post $post): array
    {
        $primaryId = (int) get_post_meta($post->ID, '_yoast_wpseo_primary_category', true);
        if ($primaryId <= 0) {
            $categories = get_the_category($post->ID);
            if (\is_array($categories) && $categories !== []) {
                $primaryId = (int) $categories[0]->term_id;
            }
        }
        if ($primaryId <= 0) {
            return ['id' => 0, 'slug' => ''];
        }

        $term = get_term($primaryId);
        if (! $term instanceof WP_Term) {
            return ['id' => 0, 'slug' => ''];
        }
        return ['id' => $primaryId, 'slug' => $term->slug];
    }

    /**
     * Only exposes meta keys explicitly registered via
     * `mua_context_post_meta_keys` — blasting out every `_meta_key` would
     * leak private state other plugins assume stays server-side.
     *
     * @return array<string, mixed>
     */
    private static function exposedMeta(WP_Post $post): array
    {
        // Auto-include every registered meta on this post type with
        // `show_in_rest: true`. Covers core, ACF, Meta Box, Pods,
        // WooCommerce, and anything else that publishes to the REST API
        // surface — no per-plugin integration required.
        $autoKeys = [];
        if (\function_exists('get_registered_meta_keys')) {
            $registered = get_registered_meta_keys('post', $post->post_type);
            if (\is_array($registered)) {
                foreach ($registered as $key => $schema) {
                    if (\is_string($key) && ! \str_starts_with($key, '_') && ! empty($schema['show_in_rest'])) {
                        $autoKeys[] = $key;
                    }
                }
            }
        }

        // Legacy filter (opt-in list of keys). Kept for back-compat with
        // existing integrations; merged with the auto-registered set.
        $legacy = (array) apply_filters('mua_context_post_meta_keys', [], $post);
        $keys   = \array_values(\array_unique(\array_merge(
            $autoKeys,
            \array_filter($legacy, 'is_string')
        )));

        $out = [];
        foreach ($keys as $key) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value !== '' && $value !== null) {
                $out[$key] = $value;
            }
        }

        /** @see mua_post_context_meta — extensions shape the final bag
         * (ACF field name normalisation, WC product pricing, etc.). */
        return (array) apply_filters('mua_post_context_meta', $out, $post);
    }

    private static function dateHuman(WP_Post $post): string
    {
        $published = (int) get_post_time('U', true, $post);
        if ($published <= 0) {
            return '';
        }
        return human_time_diff($published, current_time('timestamp')) . ' ago';
    }

    private static function estimateReadingTime(string $html): int
    {
        $words = \str_word_count(wp_strip_all_tags($html));
        if ($words <= 0) {
            return 0;
        }
        return (int) \max(1, \ceil($words / self::WPM));
    }
}
