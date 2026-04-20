<?php

declare(strict_types=1);

namespace MustUse\Pub\Jobs;

/**
 * Action Scheduler handler: clears a cached manifest for a given app.
 */
final class InvalidateManifestCache
{
    public static function register(): void
    {
        add_action('mua_invalidate_manifest_cache', [self::class, 'handle']);
    }

    public static function handle(int $appId): void
    {
        delete_transient('mua_manifest_' . $appId);
    }
}
