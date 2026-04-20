<?php

declare(strict_types=1);

namespace MustUse\Pub\Blocks;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;

/**
 * Restricts the block editor palette when editing mua_app_screen posts.
 *
 * Only mustuse-apps-pub/* blocks and the core allowlist are permitted
 */
final class AllowedBlocks {
	private const CORE_ALLOWLIST = [
		'core/paragraph',
		'core/heading',
		'core/image',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/separator',
		'core/group',
	];

	public static function register(): void {
		add_filter( 'allowed_block_types_all', [ self::class, 'filter' ], 10, 2 );
	}

	/**
	 * @param bool|string[] $allowedBlocks
	 * @param \WP_Block_Editor_Context $context
	 * @return bool|string[]
	 */
	public static function filter( $allowedBlocks, $context ) {
		if ( ! isset( $context->post ) ) {
			return $allowedBlocks;
		}

		$postType = $context->post->post_type;

		if ( $postType === App::POST_TYPE ) {
			return self::blocksForNamespace( 'mustuse-apps-pub-canvas/' );
		}

		if ( $postType === Screen::POST_TYPE ) {
			$allowed = \array_merge(
				self::CORE_ALLOWLIST,
				self::blocksForNamespace( 'mustuse-apps-pub/' )
			);
			return apply_filters( 'mua_allowed_blocks', $allowed, $context );
		}

		return $allowedBlocks;
	}

	/**
	 * Collect all registered block names matching a namespace prefix.
	 *
	 * @return string[]
	 */
	private static function blocksForNamespace( string $prefix ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = [];

		foreach ( $registry->get_all_registered() as $block ) {
			if ( \str_starts_with( $block->name, $prefix ) ) {
				$blocks[] = $block->name;
			}
		}

		return $blocks;
	}
}
