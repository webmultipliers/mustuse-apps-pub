<?php

declare(strict_types=1);

namespace MustUse\Pub\Support;

/**
 * Symmetric encryption helper for at-rest secrets (GitHub PATs, push creds).
 * AES-256-GCM with a per-site key.
 *
 * Key resolution (in order of preference):
 *   1. MUA_SECURE_STORAGE_KEY constant in wp-config.php (recommended).
 *   2. A self-generated key stored in wp_options (autoload=false).
 *
 * Callers should treat ciphertexts as opaque strings and always round-trip
 * through this class — never inspect the stored bytes directly.
 */
final class SecureStorage {
	private const OPTION_KEY = 'mua_secure_storage_key';
	private const CIPHER     = 'aes-256-gcm';

	public static function encrypt( string $plaintext ): string {
		$key = self::key();
		$iv  = \random_bytes( 12 );
		$tag = '';

		$ciphertext = \openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if ( $ciphertext === false ) {
			throw new \RuntimeException( 'SecureStorage encryption failed.' );
		}

		return 'v1.' . \base64_encode( $iv . $tag . $ciphertext );
	}

	public static function decrypt( string $payload ): ?string {
		if ( \strpos( $payload, 'v1.' ) !== 0 ) {
			return NULL;
		}

		$raw = \base64_decode( \substr( $payload, 3 ), true );
		if ( $raw === false || \strlen( $raw ) < 28 ) {
			return NULL;
		}

		$iv         = \substr( $raw, 0, 12 );
		$tag        = \substr( $raw, 12, 16 );
		$ciphertext = \substr( $raw, 28 );

		$plaintext = \openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return $plaintext === false ? NULL : $plaintext;
	}

	private static function key(): string {
		if ( \defined( 'MUA_SECURE_STORAGE_KEY' ) && \is_string( MUA_SECURE_STORAGE_KEY ) && MUA_SECURE_STORAGE_KEY !== '' ) {
			return \hash( 'sha256', MUA_SECURE_STORAGE_KEY, true );
		}

		$stored = get_option( self::OPTION_KEY );
		if ( \is_string( $stored ) && $stored !== '' ) {
			$decoded = \base64_decode( $stored, true );
			if ( $decoded !== false && \strlen( $decoded ) === 32 ) {
				return $decoded;
			}
		}

		$generated = \random_bytes( 32 );
		update_option( self::OPTION_KEY, \base64_encode( $generated ), false );
		return $generated;
	}
}
