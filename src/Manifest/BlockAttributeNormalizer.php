<?php

declare(strict_types=1);

namespace MustUse\Pub\Manifest;

use MustUse\Pub\Content\ImageSerializer;

/**
 * Resolves block-attribute references (image ids → rich shapes, hero CTA
 * fields → a single `cta` object) at manifest-build time. Scope is
 * intentionally narrow: no database lookups that scale with content
 * volume — those happen live on-device via `/content`. Extensions hook
 * `mua_block_attributes` at a later priority to add transforms.
 */
final class BlockAttributeNormalizer {
	public const IMAGE_ATTRIBUTE_IDS = [
		// Attribute ids whose values we resolve into { id, url, alt, width, height }.
		// Extensions can register more via the `mua_block_image_attribute_ids` filter.
		'imageUrl', 'image', 'posterImage', 'thumbnail', 'avatar', 'background',
	];

	public static function register(): void {
		add_filter( 'mua_block_attributes', [ self::class, 'normalize' ], 5, 3 );
	}

	/**
	 * @param array<string, mixed>|mixed $attributes
	 * @return array<string, mixed>
	 */
	public static function normalize( $attributes, string $blockName, int $postId ): array {
		if ( ! \is_array( $attributes ) ) {
			return [];
		}

		$attributes = self::normalizeImageAttributes( $attributes, $blockName );

		if ( $blockName === 'mustuse-apps-pub/hero' ) {
			$attributes = self::normalizeHero( $attributes );
		}

		return $attributes;
	}

	/**
	 * Walk every attribute whose id suggests it's an image reference, and
	 * coerce it via the shared ImageSerializer. Templates should never have
	 * to ask "is this a string or an object?".
	 *
	 * @param array<string, mixed> $attributes
	 * @return array<string, mixed>
	 */
	private static function normalizeImageAttributes( array $attributes, string $blockName ): array {
		/** @var string[] $ids */
		$ids = (array) apply_filters( 'mua_block_image_attribute_ids', self::IMAGE_ATTRIBUTE_IDS, $blockName );

		foreach ( $ids as $id ) {
			if ( ! \array_key_exists( $id, $attributes ) ) {
				continue;
			}
			$attributes[ $id ] = ImageSerializer::fromRaw( $attributes[ $id ] );
		}
		return $attributes;
	}

	/**
	 * Fold `ctaTargetType` + `ctaTargetValue` + `ctaLabel` into a single
	 * `cta` object the hero Blade can consume without knowing the publisher
	 * schema. Returns `cta: null` when any required piece is missing so the
	 * template can cleanly skip rendering.
	 *
	 * @param array<string, mixed> $attributes
	 * @return array<string, mixed>
	 */
	private static function normalizeHero( array $attributes ): array {
		$label = (string) ( $attributes['ctaLabel'] ?? '' );
		$type  = (string) ( $attributes['ctaTargetType'] ?? 'none' );
		$value = (string) ( $attributes['ctaTargetValue'] ?? '' );

		if ( $label === '' || $type === 'none' || $value === '' ) {
			$attributes['cta'] = NULL;
			return $attributes;
		}

		$attributes['cta'] = [
			'label'  => $label,
			'type'   => $type,
			'target' => $value,
			'url'    => self::resolveCtaUrl( $type, $value ),
		];
		return $attributes;
	}

	private static function resolveCtaUrl( string $type, string $value ): string {
		return match ( $type ) {
			'url'      => $value,
			'screen'   => '/' . \ltrim( $value, '/' ),
			'deeplink' => $value,
			default    => '',
		};
	}

	/**
	 * Back-compat shim for callers that used `BlockAttributeNormalizer::resolveImage()`
	 * directly. New code should use `ImageSerializer::fromRaw()`.
	 *
	 * @return array{id:int,url:string,alt:string,width:int,height:int}|null
	 */
	public static function resolveImage( mixed $raw ): ?array {
		return ImageSerializer::fromRaw( $raw );
	}
}
