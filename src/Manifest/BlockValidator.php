<?php

declare(strict_types=1);

namespace MustUse\Pub\Manifest;

/**
 * Per-block validation during manifest generation.
 *
 * Validates:
 * - native-action callback shape
 * - native-action capability against block-classified subset 
 * - Endpoint callback URLs are pub-relative
 */
final class BlockValidator {
	/** @var string[] Validation errors collected during a pass. */
	private array $errors = [];

	/**
	 * Validate a block tree and return any errors found.
	 *
	 * @param array $blocks The serialized block tree.
	 * @return string[] List of validation error messages.
	 */
	public function validate( array $blocks ): array {
		$this->errors = [];
		$this->walkBlocks( $blocks );
		return $this->errors;
	}

	private function walkBlocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			$type  = $block['type'] ?? ( $block['blockName'] ?? '' );
			$attrs = $block['attributes'] ?? ( $block['attrs'] ?? [] );

			// Validate native-action block
			if ( $type === 'mustuse-apps-pub/native-action' ) {
				$this->validateNativeAction( $attrs );
			}

			// Validate any block with action.type === 'capability'
			$action = $attrs['action'] ?? NULL;
			if ( \is_array( $action ) && ( $action['type'] ?? '' ) === 'capability' ) {
				$this->validateNativeAction( $action );
			}

			$children = $block['children'] ?? ( $block['innerBlocks'] ?? [] );
			if ( ! empty( $children ) ) {
				$this->walkBlocks( $children );
			}
		}
	}

	private function validateNativeAction( array $attrs ): void {
		$capability   = $attrs['capability'] ?? '';
		$callbackType = $attrs['callbackType'] ?? '';

		// Validate capability is block-classified
		if ( ! empty( $capability ) && ! CapabilityRegistry::isBlockClassified( $capability ) ) {
			$classification = CapabilityRegistry::classify( $capability );
			if ( $classification !== NULL ) {
				$this->errors[] = \sprintf(
					'native-action uses capability "%s" which is classified as "%s", not "block". Only block-classified capabilities are valid on native-action blocks.',
					$capability,
					$classification
				);
			} else {
				$this->errors[] = \sprintf(
					'native-action uses unknown capability "%s". Valid block capabilities: %s.',
					$capability,
					\implode( ', ', CapabilityRegistry::BLOCK_CAPABILITIES )
				);
			}
		}

		// Validate callback shape
		if ( empty( $callbackType ) ) {
			$this->errors[] = 'native-action block is missing callbackType. Every instance must declare a callback';
			return;
		}

		if ( $callbackType === 'state' ) {
			$slot = $attrs['callbackSlot'] ?? '';
			if ( empty( $slot ) ) {
				$this->errors[] = 'native-action with callback type "state" is missing callbackSlot.';
			}
		} elseif ( $callbackType === 'endpoint' ) {
			$url = $attrs['callbackUrl'] ?? '';
			if ( empty( $url ) ) {
				$this->errors[] = 'native-action with callback type "endpoint" is missing callbackUrl.';
			} elseif ( $this->isExternalUrl( $url ) ) {
				$this->errors[] = \sprintf(
					'native-action endpoint callback URL "%s" is external. Endpoint URLs must be pub-relative paths',
					$url
				);
			}
		} else {
			$this->errors[] = \sprintf(
				'native-action has invalid callbackType "%s". Must be "state" or "endpoint".',
				$callbackType
			);
		}
	}

	/**
	 * Returns true when a callbackUrl points at anything other than a
	 * pub-relative path. mandates endpoint callbacks stay on
	 * the publisher origin; letting a protocol-relative or absolute URL
	 * slip through would let extensions silently exfiltrate shell
	 * callback payloads to arbitrary hosts.
	 *
	 * Accepted shapes (return false):
	 *   /apps/foo/scan-results
	 *   apps/foo/scan-results      (bare path)
	 *   ?callback=123              (query-only)
	 *
	 * Rejected shapes (return true):
	 *   https://evil.com/x         (absolute)
	 *   //evil.com/x               (protocol-relative)
	 *   file:///etc/passwd         (any scheme)
	 *   javascript:alert(1)        (any scheme)
	 */
	private function isExternalUrl( string $url ): bool {
		if ( $url === '' ) {
			return false;
		}
		// Protocol-relative form (//host/...).
		if ( \str_starts_with( $url, '//' ) ) {
			return true;
		}
		// Any URL with a scheme (scheme:// or scheme:path). Matches
		// http, https, file, javascript, data, ftp, mailto, etc.
		if ( (bool) \preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $url ) ) {
			return true;
		}
		return false;
	}
}
