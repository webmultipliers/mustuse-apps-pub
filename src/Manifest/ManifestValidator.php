<?php

declare(strict_types=1);

namespace MustUse\Pub\Manifest;

use MustUse\Pub\Data\Models\App;

/**
 * Schema-level validation of the compiled manifest.
 *
 * Enforces:
 * - Required top-level fields
 * - Mobile app types reject desktop-reserved fields
 * - Deep link shape validation
 * - Navigation shape validation
 * - Per-block validation via BlockValidator
 */
final class ManifestValidator {
	private const DESKTOP_RESERVED_FIELDS = [
		'menu',
		'window',
		'shell_mode',
		'keyboard_shortcuts',
	];

	/** @var string[] */
	private array $errors = [];

	/**
	 * Validate a manifest and return any errors found.
	 *
	 * @return string[] List of validation error messages. Empty = valid.
	 */
	public function validate( array $manifest, App $app ): array {
		$this->errors = [];

		$this->validateRequiredFields( $manifest );
		$this->validateDesktopFields( $manifest, $app );
		$this->validateDeeplink( $manifest );
		$this->validateNavigation( $manifest );
		$this->validateFallbacks( $manifest );
		$this->validateScreenTypes( $manifest );
		$this->validateScreenBlocks( $manifest );

		return $this->errors;
	}

	/**
	 * Each screen must carry an explicit `type` of 'screen' or 'campaign'
	 * (contracts/pub-shell/manifest-schema.md).
	 */
	private function validateScreenTypes( array $manifest ): void {
		$screens = $manifest['screens'] ?? [];
		foreach ( $screens as $index => $screen ) {
			$type = $screen['type'] ?? NULL;
			if ( $type !== 'screen' && $type !== 'campaign' ) {
				$this->errors[] = \sprintf(
					'Screen at index %d is missing a valid "type" field (must be "screen" or "campaign").',
					(int) $index
				);
			}
		}
	}

	private function validateRequiredFields( array $manifest ): void {
		$required = [ 'version', 'app', 'branding', 'endpoints' ];

		foreach ( $required as $field ) {
			if ( ! isset( $manifest[ $field ] ) ) {
				$this->errors[] = \sprintf( 'Missing required manifest field: %s', $field );
			}
		}

		if ( isset( $manifest['version'] ) ) {
			$version = $manifest['version'];
			if ( ! \is_int( $version ) || $version < 1 ) {
				$this->errors[] = 'Manifest "version" must be a positive integer.';
			} elseif ( $version > \MustUse\Pub\Manifest\ManifestBuilder::SCHEMA_VERSION ) {
				$this->errors[] = \sprintf(
					'Manifest "version" %d is newer than this publisher knows about (max %d).',
					$version,
					\MustUse\Pub\Manifest\ManifestBuilder::SCHEMA_VERSION
				);
			}
		}

		if ( isset( $manifest['min_shell_version'] ) ) {
			$min = $manifest['min_shell_version'];
			if ( ! \is_int( $min ) || $min < 1 ) {
				$this->errors[] = 'Manifest "min_shell_version" must be a positive integer.';
			} elseif ( isset( $manifest['version'] ) && \is_int( $manifest['version'] ) && $min > $manifest['version'] ) {
				$this->errors[] = \sprintf(
					'Manifest "min_shell_version" (%d) cannot be higher than "version" (%d).',
					$min,
					$manifest['version']
				);
			}
		}

		if ( isset( $manifest['app'] ) ) {
			foreach ( [ 'id', 'slug', 'name', 'app_type' ] as $appField ) {
				if ( ! isset( $manifest['app'][ $appField ] ) ) {
					$this->errors[] = \sprintf( 'Missing required app field: %s', $appField );
				}
			}

			if ( isset( $manifest['app']['app_type'] ) ) {
				$this->validateSingleAppType( $manifest['app']['app_type'] );
			}

			// `app_types` (plural) is the canonical multi-platform list.
			// Singular `app_type` remains required for per-shell contract
			// compatibility, but when the plural is present each entry
			// must also satisfy the same v1-mobile constraint.
			if ( isset( $manifest['app']['app_types'] ) ) {
				$this->validateAppTypesList( $manifest['app']['app_types'] );
			}
		}
	}

	private function validateSingleAppType( mixed $appType ): void {
		if ( ! \is_string( $appType ) || $appType === '' ) {
			$this->errors[] = 'The app_type field must be a non-empty string.';
			return;
		}
		if ( \in_array( $appType, App::DESKTOP_TYPES, true ) ) {
			$this->errors[] = \sprintf(
				'App type "%s" is not yet supported in v1. Only mobile_ios and mobile_android are allowed.',
				$appType
			);
			return;
		}
		if ( ! \in_array( $appType, App::MOBILE_TYPES, true ) ) {
			$this->errors[] = \sprintf(
				'Unknown app_type "%s". Supported values: mobile_ios, mobile_android.',
				$appType
			);
		}
	}

	private function validateAppTypesList( mixed $appTypes ): void {
		if ( ! \is_array( $appTypes ) || $appTypes === [] ) {
			$this->errors[] = 'The app_types field must be a non-empty list of platform identifiers.';
			return;
		}
		foreach ( $appTypes as $type ) {
			if ( ! \is_string( $type ) || ! \in_array( $type, App::MOBILE_TYPES, true ) ) {
				$this->errors[] = \sprintf(
					'Unknown or unsupported platform "%s" in app_types. Allowed: mobile_ios, mobile_android.',
					\is_string( $type ) ? $type : \gettype( $type )
				);
			}
		}
	}

	/**
	 * Reject desktop-reserved fields for mobile app types.
	 */
	private function validateDesktopFields( array $manifest, App $app ): void {
		if ( \in_array( $app->appType(), App::DESKTOP_TYPES, true ) ) {
			return;
		}

		foreach ( self::DESKTOP_RESERVED_FIELDS as $field ) {
			if ( isset( $manifest[ $field ] ) ) {
				$this->errors[] = \sprintf(
					'Manifest contains desktop-reserved field "%s" but app targets mobile platforms only. Desktop fields are rejected for mobile app types.',
					$field
				);
			}
		}
	}

	/**
	 * Validate deep link configuration shape
	 */
	private function validateDeeplink( array $manifest ): void {
		$deeplink = $manifest['deeplink'] ?? NULL;

		if ( $deeplink === NULL ) {
			return;
		}

		if ( ! \is_array( $deeplink ) ) {
			$this->errors[] = 'Manifest "deeplink" field must be an object.';
			return;
		}

		if ( isset( $deeplink['scheme'] ) ) {
			if ( ! \preg_match( '/^[a-z][a-z0-9]*$/', $deeplink['scheme'] ) ) {
				$this->errors[] = \sprintf(
					'Deep link scheme "%s" is invalid. Must be alphanumeric lowercase starting with a letter.',
					$deeplink['scheme']
				);
			}
		}

		if ( isset( $deeplink['host'] ) ) {
			if ( ! \preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $deeplink['host'] ) ) {
				$this->errors[] = \sprintf(
					'Deep link host "%s" is not a valid hostname.',
					$deeplink['host']
				);
			}
		}
	}

	/**
	 * Validate navigation shape: `{bottom_nav: [...], drawer: [...]}`.
	 *
	 * `bottom_nav` is capped at 5 per iOS HIG tab-bar convention; Android's
	 * bottom navigation inherits the same cap. `drawer` is unbounded — it's
	 * a tree of every published screen and shells surface it behind a
	 * hamburger when the tab bar overflows.
	 */
	private function validateNavigation( array $manifest ): void {
		$navigation = $manifest['navigation'] ?? NULL;

		if ( $navigation === NULL || empty( $navigation ) ) {
			return;
		}

		$bottomNav = $navigation['bottom_nav'] ?? NULL;
		if ( \is_array( $bottomNav ) && \count( $bottomNav ) > 5 ) {
			$this->errors[] = \sprintf(
				'Bottom navigation has %d items. Maximum is 5.',
				\count( $bottomNav )
			);
		}

		if ( \array_key_exists( 'side_nav', $navigation ) && ! \is_array( $navigation['side_nav'] ) ) {
			$this->errors[] = 'Side navigation must be an array.';
		}
	}

	/**
	 * `fallback_map` → array<string, string> with known slot keys.
	 * `fallback_policy` → `{mode: web|screen_404|home, webview_base?: string}`.
	 */
	private function validateFallbacks( array $manifest ): void {
		if ( \array_key_exists( 'fallback_map', $manifest ) ) {
			$map = $manifest['fallback_map'];
			if ( ! \is_array( $map ) ) {
				$this->errors[] = 'fallback_map must be an object/array.';
			} else {
				$allowed = [ 'post', 'page', 'archive_category', 'archive_tag', 'archive_taxonomy', 'author', 'search', 'any' ];
				foreach ( $map as $slot => $slug ) {
					if ( ! \is_string( $slot ) || ! \in_array( $slot, $allowed, true ) ) {
						$this->errors[] = \sprintf( 'Unknown fallback slot "%s".', (string) $slot );
					}
					if ( ! \is_string( $slug ) || $slug === '' ) {
						$this->errors[] = \sprintf( 'fallback_map[%s] must be a non-empty screen_id string.', (string) $slot );
					}
				}
			}
		}

		if ( \array_key_exists( 'fallback_policy', $manifest ) ) {
			$policy = $manifest['fallback_policy'];
			if ( ! \is_array( $policy ) ) {
				$this->errors[] = 'fallback_policy must be an object.';
				return;
			}
			$mode = $policy['mode'] ?? '';
			if ( ! \in_array( $mode, [ 'web', 'screen_404', 'home' ], true ) ) {
				$this->errors[] = \sprintf( 'Unknown fallback_policy.mode "%s". Expected web | screen_404 | home.', (string) $mode );
			}
			if ( $mode === 'web' ) {
				$base = (string) ( $policy['webview_base'] ?? '' );
				// Empty is acceptable — the shell degrades to the `any`
				// fallback or home when the base wasn't configured yet.
				// Only reject genuinely malformed values.
				if ( $base !== '' && ! \preg_match( '#^https?://#i', $base ) ) {
					$this->errors[] = 'fallback_policy.webview_base must be an http:// or https:// URL when set.';
				}
			}
			if ( $mode === 'screen_404' && ! isset( $manifest['fallback_map']['any'] ) ) {
				$this->errors[] = 'fallback_policy.mode=screen_404 requires a screen with `_mua_is_fallback_for=any`.';
			}
		}
	}

	/**
	 * Validate all blocks in all screens via BlockValidator.
	 */
	private function validateScreenBlocks( array $manifest ): void {
		$screens = $manifest['screens'] ?? [];
		if ( empty( $screens ) ) {
			return;
		}

		$blockValidator = new BlockValidator();

		foreach ( $screens as $screen ) {
			$blockTree = $screen['block_tree'] ?? [];
			if ( ! empty( $blockTree ) ) {
				$blockErrors = $blockValidator->validate( $blockTree );
				foreach ( $blockErrors as $error ) {
					$screenId       = $screen['id'] ?? 'unknown';
					$this->errors[] = \sprintf( '[Screen: %s] %s', $screenId, $error );
				}
			}
		}
	}
}
