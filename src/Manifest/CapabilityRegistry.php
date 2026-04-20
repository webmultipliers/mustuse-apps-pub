<?php

declare(strict_types=1);

namespace MustUse\Pub\Manifest;

use MustUse\Pub\Data\Models\App;

/**
 * Static enumeration of the mobile capabilities with v1 classifications,
 * plus per-app storage of shell-advertised capabilities.
 *
 * Classifications:
 *  - block:    surfaced as native-action capability or dedicated block type
 *  - shell:    shell-internal, not exposed to the pub
 *  - deferred: known but not exposed in v1
 */
final class CapabilityRegistry {
	/**
	 * Mobile v1 capability vocabulary with classifications.
	 *
	 * @var array<string, string>
	 */
	public const MOBILE_CAPABILITIES = [
		'browser_inapp'      => 'block',
		'browser_system'     => 'block',
		'browser_auth'       => 'block',
		'camera'             => 'block',
		'photo_library'      => 'block',
		'video_record'       => 'block',
		'scanner'            => 'block',
		'share'              => 'block',
		'biometrics'         => 'block',
		'haptics'            => 'block',
		'flashlight'         => 'block',
		'geolocation'        => 'block',
		'microphone'         => 'block',
		'dialog_alert'       => 'block',
		'dialog_toast'       => 'block',
		'secure_storage'     => 'shell',
		'network'            => 'shell',
		'dialog'             => 'shell',
		'device'             => 'shell',
		'file'               => 'shell',
		'system'             => 'shell',
		'push_notifications' => 'shell',
		'nfc'                => 'deferred',
	];

	/**
	 * Block-classified capabilities — the only values valid on native-action blocks.
	 *
	 * @var string[]
	 */
	public const BLOCK_CAPABILITIES = [
		'browser_inapp',
		'browser_system',
		'browser_auth',
		'camera',
		'photo_library',
		'video_record',
		'scanner',
		'share',
		'biometrics',
		'haptics',
		'flashlight',
		'geolocation',
		'microphone',
		'dialog_alert',
		'dialog_toast',
	];

	/**
	 * Whether a capability is block-classified and can appear on a native-action block.
	 */
	public static function isBlockClassified( string $capability ): bool {
		return ( self::MOBILE_CAPABILITIES[ $capability ] ?? '' ) === 'block';
	}

	/**
	 * Whether a capability name is in the known vocabulary at all.
	 */
	public static function isKnown( string $capability ): bool {
		return isset( self::MOBILE_CAPABILITIES[ $capability ] );
	}

	/**
	 * Get the classification of a capability (block, config, shell, deferred).
	 */
	public static function classify( string $capability ): ?string {
		return self::MOBILE_CAPABILITIES[ $capability ] ?? NULL;
	}

	/**
	 * Whether a specific capability is advertised by any shell connected to the given app.
	 */
	public static function isAdvertisedBy( App $app, string $capability ): bool {
		$advertised         = self::getAdvertised( $app );
		$nativeCapabilities = $advertised['native_capabilities'] ?? [];

		return ! empty( $nativeCapabilities[ $capability ] );
	}

	/**
	 * Record a shell's capability advertisement for an app.
	 *
	 * The full advertisement payload is stored (app_type, shell_version,
	 * native_capabilities, component_registry) for developer-surface display.
	 */
	public static function recordAdvertisement( App $app, array $advertisement ): void {
		$app->updateMeta( 'shell_capabilities', $advertisement );
	}

	/**
	 * Get the stored capability advertisement for an app.
	 *
	 * @return array The full advertisement payload.
	 */
	public static function getAdvertised( App $app ): array {
		return $app->meta( 'shell_capabilities', [] );
	}

	/**
	 * Backward-compatible alias for recordAdvertisement.
	 * Called by CapabilityRoute::handle() with the full request body.
	 */
	public static function record( App $app, array $capabilities ): void {
		self::recordAdvertisement( $app, $capabilities );
	}

	/**
	 * Backward-compatible alias for getAdvertised.
	 */
	public static function get( App $app ): array {
		return self::getAdvertised( $app );
	}

	/**
	 * Get all capabilities grouped by classification.
	 *
	 * @return array<string, string[]>
	 */
	public static function allByClassification(): array {
		$grouped = [];
		foreach ( self::MOBILE_CAPABILITIES as $capability => $classification ) {
			$grouped[ $classification ][] = $capability;
		}
		return $grouped;
	}
}
