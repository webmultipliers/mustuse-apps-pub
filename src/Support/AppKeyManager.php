<?php

declare(strict_types=1);

namespace MustUse\Pub\Support;

use MustUse\Pub\Data\Models\App;

/**
 * Generates and manages per-app API keys for shell authentication.
 *
 * Keys are random 48-character hex strings. Only the bcrypt hash is
 * stored in app metadata — the plaintext key is returned exactly once
 * at generation time and cannot be retrieved afterward.
 */
final class AppKeyManager {
    public static function generate( App $app ): string {
        $plaintext = \bin2hex( \random_bytes( 24 ) );
        $hash      = \password_hash( $plaintext, PASSWORD_DEFAULT ); // Updated to use password_hash

        $app->updateMeta( 'api_key_hash', $hash );
        $app->updateMeta( 'api_key_prefix', \substr( $plaintext, 0, 8 ) );

        return $plaintext;
    }

    public static function getPrefix( App $app ): string {
        return $app->meta( 'api_key_prefix', '' );
    }

    public static function hasKey( App $app ): bool {
        return (bool) $app->meta( 'api_key_hash' );
    }
}
