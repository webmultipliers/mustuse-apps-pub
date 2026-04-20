<?php

declare(strict_types=1);

namespace MustUse\Pub\Content;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use WP_Post;
use WP_Term;

/**
 * Resolves a deep-link path to a screen ID and context.
 *
 * The resolution logic matches incoming paths against:
 * 1. Screen-specific path templates configured on mua_app_screen posts
 * 2. WordPress content URL patterns (post type + ID)
 * 3. Registered custom path handlers via the mua_deeplink_resolve filter
 *
 * Unresolvable paths return null (caller falls back to home screen).
 */
final class DeeplinkResolver {
	/**
	 * Resolve a deep-link path to a screen and context.
	 *
	 * @return array{screen_id: string, context: array}|null
	 */
	public function resolve( App $app, string $path ): ?array {
		$path = '/' . \ltrim( $path, '/' );

		// 1. Try screen-specific path templates. Shell-facing resolution
		// must never match a draft screen, or the manifest and the
		// deeplink endpoint would disagree on which screens exist.
		$screens = Screen::findByApp( $app->id(), [ 'post_status' => 'publish' ] );
		foreach ( $screens as $screen ) {
			$template = $screen->meta( 'deeplink_path', '' );
			if ( empty( $template ) ) {
				continue;
			}

			$params = $this->matchPathTemplate( $template, $path );
			if ( $params !== NULL ) {
				$context = $this->hydrateTemplateContext( $params );
				return [
					'screen_id'      => $screen->slug(),
					'context'        => $context,
					'match_strength' => 'exact',
				];
			}
		}

		// 2. Try WordPress content patterns. These identify *what* the
		//    path points at (post / term / author / search) and propose a
		//    canonical screen_id (`article-detail`, etc.). The cascade
		//    below either confirms that screen exists or falls through
		//    to a publisher-authored fallback.
		$contentResult = $this->resolveContentPath( $app, $path );
		if ( $contentResult !== NULL ) {
			return $this->applyCascade( $app, $contentResult, $screens );
		}

		// 3. Allow plugins to resolve custom paths
		$filtered = apply_filters( 'mua_deeplink_resolve', NULL, $app, $path );
		if ( \is_array( $filtered ) && isset( $filtered['screen_id'] ) ) {
			$proposed = [
				'screen_id' => (string) $filtered['screen_id'],
				'context'   => \is_array( $filtered['context'] ?? NULL ) ? $filtered['context'] : [],
			];
			return $this->applyCascade( $app, $proposed, $screens );
		}

		// 4. No content matched. Look for an `any`-slot fallback screen
		//    (the publisher's 404) so the shell renders something
		//    actionable rather than dead-ending.
		$anyFallback = Screen::findFallback( $app->id(), [ 'any' ] );
		if ( $anyFallback instanceof Screen ) {
			return [
				'screen_id'      => $anyFallback->slug(),
				'context'        => [],
				'match_strength' => 'fallback',
			];
		}

		return NULL;
	}

	/**
	 * Walk the template cascade: if the proposed screen_id exists in the
	 * app's published screens, keep it. Otherwise fall through to a
	 * fallback screen in order of specificity. Returns the resolved
	 * result with `match_strength` set (exact | typed | fallback).
	 *
	 * @param array{screen_id: string, context: array<string, mixed>}      $proposed
	 * @param array<int, Screen>                                           $screens
	 * @return array{screen_id: string, context: array<string, mixed>, match_strength: string}
	 */
	private function applyCascade( App $app, array $proposed, array $screens ): array {
		$slug = (string) $proposed['screen_id'];
		foreach ( $screens as $screen ) {
			if ( $screen->slug() === $slug ) {
				return [
					'screen_id'      => $slug,
					'context'        => $proposed['context'],
					'match_strength' => 'exact',
				];
			}
		}

		$slots = $this->fallbackSlotsFor( $slug, $proposed['context'] );
		$fallback = Screen::findFallback( $app->id(), $slots );
		if ( $fallback instanceof Screen ) {
			return [
				'screen_id'      => $fallback->slug(),
				'context'        => $proposed['context'],
				'match_strength' => 'fallback',
			];
		}

		// Even without an authored fallback, return the proposed slug so
		// existing manifests that happen to host a screen by that slug
		// (without using the fallback meta) still work. Shell then sees
		// `match_strength=typed` and can flag in dev builds.
		return [
			'screen_id'      => $slug,
			'context'        => $proposed['context'],
			'match_strength' => 'typed',
		];
	}

	/**
	 * Fallback-slot preference list for a proposed screen_id, ordered
	 * most-specific → most-generic. The post/page/category/tag/author/
	 * search slots map directly; unknown screen_ids drop to `any`.
	 *
	 * @param array<string, mixed> $context
	 * @return list<string>
	 */
	private function fallbackSlotsFor( string $screenId, array $context ): array {
		if ( isset( $context['post'] ) && \is_array( $context['post'] ) ) {
			$postType = (string) ( $context['post']['post_type'] ?? 'post' );
			if ( $postType === 'page' ) {
				return [ 'page', 'post', 'any' ];
			}
			return [ 'post', 'any' ];
		}
		if ( isset( $context['term'] ) && \is_array( $context['term'] ) ) {
			$taxonomy = (string) ( $context['term']['taxonomy'] ?? '' );
			if ( $taxonomy === 'category' ) {
				return [ 'archive_category', 'archive_taxonomy', 'any' ];
			}
			if ( $taxonomy === 'post_tag' ) {
				return [ 'archive_tag', 'archive_taxonomy', 'any' ];
			}
			return [ 'archive_taxonomy', 'any' ];
		}
		if ( isset( $context['author'] ) ) {
			return [ 'author', 'any' ];
		}
		if ( isset( $context['search'] ) ) {
			return [ 'search', 'any' ];
		}
		return [ 'any' ];
	}

	/**
	 * Match a path against a template like /article/{id} or /category/{slug}.
	 *
	 * @return array<string, string>|null Extracted parameters, or null if no match.
	 */
	private function matchPathTemplate( string $template, string $path ): ?array {
		// Reject publisher-authored templates that would allow
		// catastrophic-backtracking or arbitrary regex injection.
		// Only literal path segments and `{name}` placeholders
		// drawn from a fixed alphabet are accepted.
		if ( ! self::isSafeTemplate( $template ) ) {
			return NULL;
		}

		$pattern = \preg_replace( '/\{(\w+)\}/', '(?P<$1>[^/]+)', $template );
		$pattern = '#^' . $pattern . '$#';

		if ( \preg_match( $pattern, $path, $matches ) ) {
			$params = [];
			foreach ( $matches as $key => $value ) {
				if ( \is_string( $key ) ) {
					$params[ $key ] = $value;
				}
			}
			return $params;
		}

		return NULL;
	}

	/**
	 * Accept path templates composed only of literal segments (letters,
	 * digits, `-`, `_`, `.`) and `{name}` placeholders with alphanumeric
	 * placeholder names.
	 *
	 * Rejects anchors, alternations, quantifiers, escape sequences, and
	 * any characters that would let a publisher-authored regex escape
	 * the intended shape (audit S12 — ReDoS). Also rejects empty and
	 * relative path segments (`//`, `/./`, `/../`) so two templates that
	 * match the same URL can't disagree on which one wins.
	 */
	public static function isSafeTemplate( string $template ): bool {
		if ( $template === '' || \strlen( $template ) > 200 ) {
			return false;
		}

		// Reject empty (`//`) and dot (`/./`, `/../`) segments on the raw
		// template, not the placeholder-stripped form — stripping `{name}`
		// from `/app/{app}/screen/{screen}` would otherwise look like `//`
		// and trip the empty-segment guard.
		if ( \str_contains( $template, '//' ) ) {
			return false;
		}
		if ( \preg_match( '#(^|/)\.{1,2}(/|$)#', $template ) ) {
			return false;
		}

		$withoutPlaceholders = \preg_replace( '/\{\w+\}/', '', $template );
		if ( $withoutPlaceholders === NULL ) {
			return false;
		}

		return (bool) \preg_match( '#^[A-Za-z0-9/_.\-]*$#', $withoutPlaceholders );
	}

	/**
	 * Match common WP URL shapes:
	 *   /article|post/{id|slug}  → article-detail
	 *   /page/{id|slug}          → page-detail
	 *   /category|tag/{slug}     → {category|tag}-detail
	 *   /author/{slug|id}        → author-detail
	 *   /search[?q=…]            → search-results
	 *
	 * Matches return rich context via ContextBuilder so post-* blocks
	 * read `context.post.title` regardless of entry point.
	 *
	 * @return array{screen_id: string, context: array}|null
	 */
	private function resolveContentPath( App $app, string $path ): ?array {
		if ( \preg_match( '#^/search(?:\?.*)?$#', $path ) ) {
			$query    = '';
			$queryPos = \strpos( $path, '?' );
			if ( $queryPos !== false ) {
				\parse_str( \substr( $path, $queryPos + 1 ), $parsed );
				$rawQ  = $parsed['q'] ?? '';
				$query = \is_string( $rawQ ) ? sanitize_text_field( $rawQ ) : '';
			}
			return [
				'screen_id' => 'search-results',
				'context'   => [ 'search' => [ 'query' => $query ] ],
			];
		}

		if ( \preg_match( '#^/(?:article|post)/([^/]+)$#', $path, $m ) ) {
			$post = $this->findPostByIdOrSlug( $m[1], 'post' );
			if ( $post instanceof WP_Post ) {
				return [
					'screen_id' => 'article-detail',
					'context'   => [ 'post' => ContextBuilder::postContext( $post ) ],
				];
			}
		}

		if ( \preg_match( '#^/page/([^/]+)$#', $path, $m ) ) {
			$post = $this->findPostByIdOrSlug( $m[1], 'page' );
			if ( $post instanceof WP_Post ) {
				return [
					'screen_id' => 'page-detail',
					'context'   => [ 'post' => ContextBuilder::postContext( $post ) ],
				];
			}
		}

		if ( \preg_match( '#^/(category|tag)/([^/]+)$#', $path, $m ) ) {
			$taxonomy = $m[1] === 'tag' ? 'post_tag' : 'category';
			$term     = get_term_by( 'slug', $m[2], $taxonomy );
			if ( $term instanceof WP_Term ) {
				return [
					'screen_id' => $m[1] . '-detail',
					'context'   => [ 'term' => ContextBuilder::termContext( $term ) ],
				];
			}
		}

		if ( \preg_match( '#^/author/([^/]+)$#', $path, $m ) ) {
			$user = \ctype_digit( $m[1] )
				? get_user_by( 'id', (int) $m[1] )
				: get_user_by( 'slug', $m[1] );
			if ( $user !== false ) {
				return [
					'screen_id' => 'author-detail',
					'context'   => [ 'author' => ContextBuilder::authorContext( $user ) ],
				];
			}
		}

		return NULL;
	}

	/**
	 * Hydrate extracted template params (`{slug}`, `{taxonomy}+{term}`,
	 * `{author}`) into rich WP context. Params also surface raw under
	 * `context.params` so templates with unknown keys (e.g. `{bar}`) can
	 * still read them.
	 *
	 * @param array<string, string> $params
	 * @return array<string, mixed>
	 */
	private function hydrateTemplateContext( array $params ): array {
		$context = [ 'params' => $params ];

		if ( isset( $params['slug'] ) || isset( $params['id'] ) ) {
			$post = $this->findPostByIdOrSlug(
				(string) ( $params['id'] ?? $params['slug'] ),
				'any'
			);
			if ( $post instanceof WP_Post ) {
				$context['post'] = ContextBuilder::postContext( $post );
			}
		}

		if ( isset( $params['taxonomy'] ) && isset( $params['term'] ) ) {
			$term = get_term_by( 'slug', $params['term'], $params['taxonomy'] );
			if ( $term instanceof WP_Term ) {
				$context['term'] = ContextBuilder::termContext( $term );
			}
		}

		if ( isset( $params['author'] ) ) {
			$user = \ctype_digit( (string) $params['author'] )
				? get_user_by( 'id', (int) $params['author'] )
				: get_user_by( 'slug', (string) $params['author'] );
			if ( $user !== false ) {
				$context['author'] = ContextBuilder::authorContext( $user );
			}
		}

		return $context;
	}

	private function findPostByIdOrSlug( string $idOrSlug, string $postType ): ?WP_Post {
		if ( \ctype_digit( $idOrSlug ) ) {
			$post = get_post( (int) $idOrSlug );
		} else {
			$postTypeArg = $postType === 'any' ? 'any' : $postType;
			$post        = get_page_by_path( $idOrSlug, OBJECT, $postTypeArg );
			if ( ! $post instanceof WP_Post ) {
				// CPTs that don't register a hierarchical path resolver
				// aren't found by get_page_by_path — fall back to the
				// name-based WP_Query lookup.
				$query = new \WP_Query( [
					'name'           => $idOrSlug,
					'post_type'      => $postTypeArg,
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'no_found_rows'  => true,
				] );
				$post  = $query->posts[0] ?? NULL;
			}
		}

		return $post instanceof WP_Post && $post->post_status === 'publish' ? $post : NULL;
	}
}
