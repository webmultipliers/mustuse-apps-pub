<?php

declare(strict_types=1);

namespace MustUse\Pub\Content;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;

/**
 * Maps app routes to screens for delivery to shells.
 *
 * Routes are stored as app metadata in this shape:
 *   {
 *       "/": "screen_home_12",
 *       "/post/{id}": "screen_single_15",
 *       "/category/{id}": "screen_feed_18",
 *       "/settings": "screen_settings_22"
 *   }
 *
 * Patterns are publisher-authored, so they pass through the same literal-
 * segment/`{name}` placeholder validation as DeeplinkResolver before they
 * reach preg_match. Unsafe templates (anchors, alternations, quantifiers,
 * escape sequences) are rejected rather than compiled, preventing regex
 * injection and catastrophic backtracking.
 */
final class RouteMapper {
    public function resolveRoute( App $app, string $path ): ?array {
        $routes = $app->meta( 'routing', [] );

        if ( ! \is_array( $routes ) ) {
            return NULL;
        }

        foreach ( $routes as $route => $screenId ) {
            if ( ! \is_string( $route ) || ! \is_string( $screenId ) ) {
                continue;
            }
            if ( ! self::isSafeTemplate( $route ) ) {
                continue;
            }
            if ( $this->matchRoute( $route, $path ) ) {
                return $this->fetchScreenData( $screenId );
            }
        }

        return NULL;
    }

    private function matchRoute( string $route, string $path ): bool {
        $pattern = \preg_replace( '/\{[^}]+\}/', '[^/]+', $route );
        return \preg_match( '#^' . $pattern . '$#', $path ) === 1;
    }

    /**
     * Accept only literal path segments (letters, digits, `-`, `_`, `.`,
     * `/`) and `{name}` placeholders with alphanumeric/underscore names.
     * Anything else is treated as a hostile template and skipped. Kept
     * byte-for-byte in sync with DeeplinkResolver::isSafeTemplate — both
     * resolvers must agree on template shape, otherwise the two can
     * diverge on which template a URL belongs to.
     */
    private static function isSafeTemplate( string $template ): bool {
        if ( $template === '' || \strlen( $template ) > 200 ) {
            return FALSE;
        }

        // See DeeplinkResolver::isSafeTemplate — check raw template, not
        // placeholder-stripped, or legit `/a/{x}/b/{y}` shapes trip the
        // empty-segment guard.
        if ( \str_contains( $template, '//' ) ) {
            return FALSE;
        }
        if ( \preg_match( '#(^|/)\.{1,2}(/|$)#', $template ) ) {
            return FALSE;
        }

        $withoutPlaceholders = \preg_replace( '/\{\w+\}/', '', $template );
        if ( $withoutPlaceholders === NULL ) {
            return FALSE;
        }

        return (bool) \preg_match( '#^[A-Za-z0-9/_.\-]*$#', $withoutPlaceholders );
    }

    private function fetchScreenData( string $screenId ): ?array {
        // Expect screenId in the format 'screen_slug_123' or just the numeric ID.
        if ( \preg_match( '/(\d+)$/', $screenId, $matches ) ) {
            $id     = (int) $matches[1];
            $screen = Screen::find( $id );
            if ( $screen ) {
                return [
                    'screen_id' => $screenId,
                    'id'        => $screen->id(),
                    'slug'      => $screen->slug(),
                    'title'     => $screen->title(),
                    'icon'      => $screen->icon(),
                    'blocks'    => $screen->blockTree(),
                ];
            }
        }
        return NULL;
    }
}
