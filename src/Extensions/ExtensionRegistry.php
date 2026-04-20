<?php

declare(strict_types=1);

namespace MustUse\Pub\Extensions;

use MustUse\Pub\Data\Models\App;

/**
 * Extension Registry.
 *
 * Provides the official surface for third-party WordPress plugins to
 * contribute custom native blocks, REST endpoints, and manifest data
 * to MustUse Apps.
 */
final class ExtensionRegistry {
	/** @var array<string, array> Keyed by plugin_slug. */
	private static array $extensions = [];

	private static bool $initialized = false;

	/**
	 * Register a third-party extension.
	 *
	 * @param array{
	 *     plugin_slug: string,
	 *     plugin_name: string,
	 *     plugin_version: string,
	 *     default_apps?: string[]
	 * } $args
	 */
	public static function register( array $args ): void {
		$slug = sanitize_key( $args['plugin_slug'] ?? '' );

		if ( $slug === '' ) {
			return;
		}

		self::$extensions[ $slug ] = [
			'plugin_slug'    => $slug,
			'plugin_name'    => sanitize_text_field( $args['plugin_name'] ?? $slug ),
			'plugin_version' => sanitize_text_field( $args['plugin_version'] ?? '0.0.0' ),
			'default_apps'   => $args['default_apps'] ?? [],
		];
	}

	/**
	 * Get all registered extensions.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		self::ensureInitialized();
		return self::$extensions;
	}

	/**
	 * Get extensions that are active for a specific app.
	 *
	 * @return array<string, array>
	 */
	public static function getActiveForApp( App $app ): array {
		self::ensureInitialized();

		$activeExtensions = $app->meta( 'active_extensions', [] );

		if ( ! \is_array( $activeExtensions ) || empty( $activeExtensions ) ) {
			return [];
		}

		return \array_filter(
			self::$extensions,
			static fn( array $ext ) => \in_array( $ext['plugin_slug'], $activeExtensions, true )
		);
	}

	/**
	 * Check whether a specific extension is active for an app.
	 */
	public static function isActiveForApp( App $app, string $pluginSlug ): bool {
		$activeExtensions = $app->meta( 'active_extensions', [] );

		if ( ! \is_array( $activeExtensions ) ) {
			return false;
		}

		return \in_array( $pluginSlug, $activeExtensions, true );
	}

	/**
	 * Get a single registered extension by slug.
	 */
	public static function get( string $pluginSlug ): ?array {
		self::ensureInitialized();
		return self::$extensions[ $pluginSlug ] ?? NULL;
	}

	/**
	 * Fire the registration hook so third-party plugins can register.
	 *
	 * Called once lazily on first access.
	 */
	private static function ensureInitialized(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		/**
		 * Action: third-party plugins register themselves here.
		 *
		 * Plugins should call mua_register_extension() inside this hook.
		 */
		do_action( 'mua_register_extensions' );
	}

	/**
	 * Reset state (for testing).
	 */
	public static function reset(): void {
		self::$extensions  = [];
		self::$initialized = false;
	}
}
