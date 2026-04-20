<?php

declare(strict_types=1);

namespace MustUse\Pub\Blocks;

/**
 * Per-screen block markup for the content-showcase demo app.
 *
 * Each static method returns the `post_content` of a single Screen in
 * the demo. The `ContentShowcaseScaffold` wires these up with the right
 * routing metadata so the app mirrors a default WordPress editorial
 * site (home feed, post detail, categories, author, search, saved,
 * about, 404) using only blocks the plugin ships.
 */
final class ContentShowcasePatterns
{
    public static function home(): string
    {
        return \implode("\n\n", [
            self::block('hero', [
                'heading'    => 'Latest from the site',
                'subheading' => 'A sample mobile app powered entirely by your WordPress content.',
            ]),
            self::block('breaking-banner', [ 'text' => 'Connect this to a category called "Breaking" to surface flagged stories.' ]),
            self::heading('Latest'),
            self::block('article-list', [ 'postType' => 'post', 'count' => 10 ]),
            self::heading('Categories'),
            self::block('category-pills', [ 'taxonomy' => 'category', 'count' => 12 ]),
            self::heading('Trending'),
            self::block('trending', [ 'count' => 5, 'windowDays' => 7 ]),
        ]);
    }

    public static function postDetail(): string
    {
        return \implode("\n\n", [
            self::block('post-featured-image', []),
            self::block('post-title', []),
            self::block('post-author-avatar', []),
            self::block('post-author-name', []),
            self::block('post-date', []),
            self::block('post-reading-time', []),
            self::block('post-content', []),
            self::block('post-terms', [ 'taxonomy' => 'post_tag' ]),
            self::action('share', 'Share this post', 'share.post', [ 'message' => '{{ context.post.url }}' ]),
            self::block('bookmark-toggle', []),
            self::heading('Related stories'),
            self::block('related-articles', [ 'count' => 4 ]),
            self::block('post-comments', [ 'heading' => 'Comments' ]),
        ]);
    }

    public static function pageDetail(): string
    {
        return \implode("\n\n", [
            self::block('post-title', []),
            self::block('post-content', []),
        ]);
    }

    public static function categoriesIndex(): string
    {
        return \implode("\n\n", [
            self::heading('Browse categories'),
            self::block('category-pills', [ 'taxonomy' => 'category', 'count' => 50 ]),
        ]);
    }

    public static function categoryDetail(): string
    {
        return \implode("\n\n", [
            self::block('section-heading', [ 'text' => 'In this category', 'level' => 'h3' ]),
            self::block('article-list', [ 'postType' => 'post', 'count' => 20 ]),
        ]);
    }

    public static function tagDetail(): string
    {
        return \implode("\n\n", [
            self::block('section-heading', [ 'text' => 'Tagged', 'level' => 'h3' ]),
            self::block('article-list', [ 'postType' => 'post', 'count' => 20 ]),
        ]);
    }

    public static function authorDetail(): string
    {
        return \implode("\n\n", [
            self::block('author-card', []),
            self::heading('Articles by this author'),
            self::block('article-list', [ 'postType' => 'post', 'count' => 20 ]),
        ]);
    }

    public static function search(): string
    {
        return \implode("\n\n", [
            self::block('search', [ 'placeholder' => 'Search articles' ]),
            self::block('search-results', [ 'count' => 20 ]),
        ]);
    }

    public static function saved(): string
    {
        return \implode("\n\n", [
            self::heading('Recently read'),
            self::block('read-history-list', [ 'count' => 10 ]),
            self::heading('Bookmarks'),
            self::block('text', [ 'content' => '<p>Your saved articles appear here.</p>', 'align' => 'left' ]),
        ]);
    }

    public static function about(): string
    {
        return \implode("\n\n", [
            self::block('hero', [
                'heading'    => 'About this app',
                'subheading' => 'Built with the MustUse Apps Publisher — WordPress as the content engine, NativePHP as the shell.',
            ]),
            self::block('text', [
                'content' => '<p>Edit this screen in the WordPress admin to replace the default copy. Add your mission, team, or contact information.</p>',
                'align'   => 'left',
            ]),
        ]);
    }

    public static function notFound(): string
    {
        return \implode("\n\n", [
            self::block('section-heading', [ 'text' => 'We couldn\'t find that', 'level' => 'h2' ]),
            self::block('text', [
                'content' => '<p>The page you\'re looking for has moved or no longer exists. Try a search, or head back to the latest stories.</p>',
                'align'   => 'left',
            ]),
            self::block('search', [ 'placeholder' => 'Search articles' ]),
            self::heading('Latest'),
            self::block('article-list', [ 'postType' => 'post', 'count' => 5 ]),
        ]);
    }

    private static function heading(string $text): string
    {
        return self::block('section-heading', [ 'text' => $text, 'level' => 'h3' ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function action(string $capability, string $label, string $slot, array $extra = []): string
    {
        return self::block('native-action', \array_merge([
            'capability'   => $capability,
            'label'        => $label,
            'callbackType' => 'state',
            'callbackSlot' => $slot,
            'feedback'     => 'toast',
        ], $extra));
    }

    /** @param array<string, mixed> $attrs */
    private static function block(string $slug, array $attrs): string
    {
        $json = empty($attrs) ? '' : ' ' . wp_json_encode($attrs);
        return \sprintf('<!-- wp:mustuse-apps-pub/%s%s /-->', $slug, $json);
    }
}
