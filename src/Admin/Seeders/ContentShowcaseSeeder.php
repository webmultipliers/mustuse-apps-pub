<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin\Seeders;

/**
 * Seeds the WordPress content the content-showcase demo needs to
 * render meaningfully — 5 categories, 8 tags, 15 posts, 2 pages, and
 * one nav menu wired to the seeded categories + pages.
 *
 * Idempotent: every created item is tagged with
 * `_mua_seeded_by=content-showcase` (term → term meta; post/attachment
 * → post meta). `seed()` skips any slug that already exists under
 * that flag; `reset()` deletes the whole set plus the attached images.
 *
 * Pure, no randomness. All copy lives in `DemoCopy`.
 */
final class ContentShowcaseSeeder
{
    public const SEED_FLAG      = 'content-showcase';
    public const MENU_LOCATION  = 'mua_content_showcase';
    public const MENU_NAME      = 'Content Showcase Nav';

    /**
     * Run the seed. Returns a per-kind count so the admin notice can
     * report "Created 15 posts, 5 categories, 8 tags, 2 pages".
     *
     * @return array{categories: int, tags: int, posts: int, pages: int, menu_items: int}
     */
    public static function seed(): array
    {
        $categoryIds = self::seedCategories();
        $tagIds      = self::seedTags();
        $postIds     = self::seedPosts($categoryIds, $tagIds);
        $pageIds     = self::seedPages();
        $menuItems   = self::seedMenu($categoryIds, $pageIds);

        return [
            'categories' => \count($categoryIds),
            'tags'       => \count($tagIds),
            'posts'      => \count($postIds),
            'pages'      => \count($pageIds),
            'menu_items' => $menuItems,
        ];
    }

    /**
     * Reverse `seed()` — delete every seeded post/page/term/attachment
     * plus the seeded nav menu. Safe to call on a non-seeded site.
     *
     * @return array{categories: int, tags: int, posts: int, pages: int, attachments: int, menu: int}
     */
    public static function reset(): array
    {
        $postsDeleted = self::deleteSeededPosts('post');
        $pagesDeleted = self::deleteSeededPosts('page');
        $catsDeleted  = self::deleteSeededTerms('category');
        $tagsDeleted  = self::deleteSeededTerms('post_tag');
        $attsDeleted  = DemoImageFactory::reset();
        $menuDeleted  = self::deleteSeededMenu();

        return [
            'categories'  => $catsDeleted,
            'tags'        => $tagsDeleted,
            'posts'       => $postsDeleted,
            'pages'       => $pagesDeleted,
            'attachments' => $attsDeleted,
            'menu'        => $menuDeleted,
        ];
    }

    public static function isSeeded(): bool
    {
        $ids = \function_exists('get_posts') ? get_posts([
            'post_type'   => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => '_mua_seeded_by', 'value' => self::SEED_FLAG, 'compare' => '=' ],
            ],
        ]) : [];
        return ! empty($ids);
    }

    /**
     * @return array<string, int> slug → term_id
     */
    private static function seedCategories(): array
    {
        $ids = [];
        foreach (DemoCopy::categories() as $row) {
            $term = self::ensureTerm($row['slug'], $row['name'], 'category', $row['description']);
            if ($term !== 0) {
                $ids[$row['slug']] = $term;
            }
        }
        return $ids;
    }

    /**
     * @return array<string, int> slug → term_id
     */
    private static function seedTags(): array
    {
        $ids = [];
        foreach (DemoCopy::tags() as $row) {
            $term = self::ensureTerm($row['slug'], $row['name'], 'post_tag');
            if ($term !== 0) {
                $ids[$row['slug']] = $term;
            }
        }
        return $ids;
    }

    /**
     * @param array<string, int> $categoryIds
     * @param array<string, int> $tagIds
     * @return list<int>
     */
    private static function seedPosts(array $categoryIds, array $tagIds): array
    {
        $now        = \function_exists('current_time') ? current_time('timestamp') : \time();
        $created    = [];
        $userId     = \function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        foreach (DemoCopy::posts() as $row) {
            if (self::findPostBySlug($row['slug'], 'post') > 0) {
                continue;
            }

            $publishedAt = \gmdate('Y-m-d H:i:s', $now - ($row['day_offset'] * 86400));
            $featuredId  = DemoImageFactory::create($row['slug'], $row['title']);

            $postId = wp_insert_post([
                'post_type'     => 'post',
                'post_status'   => 'publish',
                'post_name'     => $row['slug'],
                'post_title'    => $row['title'],
                'post_excerpt'  => $row['excerpt'],
                'post_content'  => DemoCopy::composeBody($row['body']),
                'post_author'   => $userId,
                'post_date_gmt' => $publishedAt,
                'post_date'     => $publishedAt,
                'comment_status' => 'open',
                'meta_input'    => [
                    '_mua_seeded_by' => self::SEED_FLAG,
                ],
            ], true);

            if (is_wp_error($postId) || ! $postId) {
                continue;
            }
            $postId = (int) $postId;

            if ($featuredId > 0) {
                set_post_thumbnail($postId, $featuredId);
            }

            if (isset($categoryIds[$row['category']])) {
                wp_set_post_terms($postId, [ $categoryIds[$row['category']] ], 'category', false);
            }

            $tagTermIds = [];
            foreach ($row['tags'] as $tagSlug) {
                if (isset($tagIds[$tagSlug])) {
                    $tagTermIds[] = $tagIds[$tagSlug];
                }
            }
            if ($tagTermIds !== []) {
                wp_set_post_terms($postId, $tagTermIds, 'post_tag', false);
            }

            $created[] = $postId;
        }
        return $created;
    }

    /**
     * @return list<int>
     */
    private static function seedPages(): array
    {
        $userId  = \function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $created = [];
        foreach (DemoCopy::pages() as $row) {
            if (self::findPostBySlug($row['slug'], 'page') > 0) {
                continue;
            }
            $pageId = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_name'    => $row['slug'],
                'post_title'   => $row['title'],
                'post_content' => DemoCopy::composeBody($row['body']),
                'post_author'  => $userId,
                'meta_input'   => [
                    '_mua_seeded_by' => self::SEED_FLAG,
                ],
            ], true);
            if (is_wp_error($pageId) || ! $pageId) {
                continue;
            }
            $created[] = (int) $pageId;
        }
        return $created;
    }

    /**
     * Build (or re-use) a nav menu and populate it with items pointing
     * at the seeded category archives + pages.
     *
     * @param array<string, int> $categoryIds
     * @param list<int>          $pageIds
     * @return int Number of menu items added (0 when WP menu APIs are
     *             absent, e.g. in unit tests).
     */
    private static function seedMenu(array $categoryIds, array $pageIds): int
    {
        if (! \function_exists('wp_get_nav_menu_object')
            || ! \function_exists('wp_create_nav_menu')
            || ! \function_exists('wp_update_nav_menu_item')) {
            return 0;
        }

        $menu = wp_get_nav_menu_object(self::MENU_NAME);
        if (! $menu) {
            $menuId = wp_create_nav_menu(self::MENU_NAME);
            if (is_wp_error($menuId)) {
                return 0;
            }
            $menuId = (int) $menuId;
        } else {
            $menuId = (int) $menu->term_id;
        }

        update_term_meta($menuId, '_mua_seeded_by', self::SEED_FLAG);

        if (\function_exists('wp_get_nav_menu_items')) {
            $existing = wp_get_nav_menu_items($menuId);
            if (\is_array($existing) && $existing !== []) {
                foreach ($existing as $item) {
                    wp_delete_post((int) $item->ID, true);
                }
            }
        }

        $added = 0;
        $order = 1;
        foreach ($categoryIds as $termId) {
            $result = wp_update_nav_menu_item($menuId, 0, [
                'menu-item-object'    => 'category',
                'menu-item-object-id' => $termId,
                'menu-item-type'      => 'taxonomy',
                'menu-item-status'    => 'publish',
                'menu-item-position'  => $order++,
            ]);
            if (! is_wp_error($result)) {
                $added++;
            }
        }
        foreach ($pageIds as $pageId) {
            $result = wp_update_nav_menu_item($menuId, 0, [
                'menu-item-object'    => 'page',
                'menu-item-object-id' => $pageId,
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
                'menu-item-position'  => $order++,
            ]);
            if (! is_wp_error($result)) {
                $added++;
            }
        }

        // Assign the menu to the theme location registered by the
        // plugin (`mua_content_showcase`). Idempotent.
        if (\function_exists('get_theme_mod') && \function_exists('set_theme_mod')) {
            $locations = (array) get_theme_mod('nav_menu_locations', []);
            $locations[self::MENU_LOCATION] = $menuId;
            set_theme_mod('nav_menu_locations', $locations);
        }

        return $added;
    }

    private static function ensureTerm(string $slug, string $name, string $taxonomy, string $description = ''): int
    {
        if (! \function_exists('term_exists') || ! \function_exists('wp_insert_term') || ! \function_exists('update_term_meta')) {
            return 0;
        }
        $existing = term_exists($slug, $taxonomy);
        if (\is_array($existing) && isset($existing['term_id'])) {
            return (int) $existing['term_id'];
        }

        $result = wp_insert_term($name, $taxonomy, [
            'slug'        => $slug,
            'description' => $description,
        ]);
        if (is_wp_error($result)) {
            return 0;
        }
        $termId = (int) $result['term_id'];
        update_term_meta($termId, '_mua_seeded_by', self::SEED_FLAG);
        return $termId;
    }

    private static function findPostBySlug(string $slug, string $postType): int
    {
        $ids = \function_exists('get_posts') ? get_posts([
            'post_type'   => $postType,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'name'        => $slug,
        ]) : [];
        return \is_array($ids) && $ids !== [] ? (int) $ids[0] : 0;
    }

    private static function deleteSeededPosts(string $postType): int
    {
        $ids = \function_exists('get_posts') ? get_posts([
            'post_type'   => $postType,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => '_mua_seeded_by', 'value' => self::SEED_FLAG, 'compare' => '=' ],
            ],
        ]) : [];
        $deleted = 0;
        foreach (\is_array($ids) ? $ids : [] as $id) {
            if (wp_delete_post((int) $id, true)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private static function deleteSeededTerms(string $taxonomy): int
    {
        $terms = \function_exists('get_terms') ? get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'meta_query' => [
                [ 'key' => '_mua_seeded_by', 'value' => self::SEED_FLAG, 'compare' => '=' ],
            ],
        ]) : [];
        if (is_wp_error($terms)) {
            return 0;
        }
        $deleted = 0;
        foreach (\is_array($terms) ? $terms : [] as $term) {
            if ($term instanceof \WP_Term && wp_delete_term($term->term_id, $taxonomy)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private static function deleteSeededMenu(): int
    {
        if (! \function_exists('wp_get_nav_menu_object') || ! \function_exists('wp_delete_nav_menu')) {
            return 0;
        }
        $menu = wp_get_nav_menu_object(self::MENU_NAME);
        if (! $menu) {
            return 0;
        }
        $result = wp_delete_nav_menu((int) $menu->term_id);
        return $result === true ? 1 : 0;
    }
}
