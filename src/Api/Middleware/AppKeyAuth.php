<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Middleware;

use MustUse\Pub\Data\Models\App;
use WP_Error;
use WP_REST_Request;

/**
 * Authenticates inbound requests from mobile shells using a per-app API key.
 *
 * Each app has a unique API key compiled into its shell at build time.
 * The key is sent as a Bearer token and verified against a bcrypt hash
 * stored in app metadata.
 */
final class AppKeyAuth
{
    public static function verify(WP_REST_Request $request): bool|WP_Error
    {
        $authorization = $request->get_header('Authorization');

        // Return a single opaque "unauthorized" error in every failure
        // path so a caller cannot distinguish "this app exists but your
        // key is wrong" from "this app does not exist" (audit S8).
        $unauthorized = static function (): WP_Error {
            return new WP_Error(
                'mua_unauthorized',
                __('Unauthorized.', 'mustuse-apps-pub'),
                ['status' => 401]
            );
        };

        if (! $authorization || ! \str_starts_with($authorization, 'Bearer ')) {
            return $unauthorized();
        }

        $key  = \substr($authorization, 7);
        $slug = (string) $request->get_param('app_slug');

        if ($slug === '' || \strlen($key) < 16 || \strlen($key) > 512) {
            return $unauthorized();
        }

        $app = App::findBySlug($slug);

        if (! $app) {
            return $unauthorized();
        }

        $storedHash = $app->meta('api_key_hash');

        if (! $storedHash || ! \password_verify($key, $storedHash)) {
            return $unauthorized();
        }

        return true;
    }
}
