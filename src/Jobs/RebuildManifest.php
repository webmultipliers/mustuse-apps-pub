<?php

declare(strict_types=1);

namespace MustUse\Pub\Jobs;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Manifest\ManifestBuilder;
use MustUse\Pub\Support\ManifestSigner;

/**
 * Action Scheduler handler: rebuilds, signs, and caches a manifest for a given
 * app. Cache shape and TTL mirror ManifestRoute so the route's read path can
 * consume what this job writes.
 */
final class RebuildManifest
{
    public static function register(): void
    {
        add_action('mua_rebuild_manifest', [self::class, 'handle']);
    }

    public static function handle(int $appId): void
    {
        $app = App::find($appId);

        if (! $app) {
            return;
        }

        $manifest = (new ManifestBuilder())->build($app);
        [$manifest, $signature] = ManifestSigner::sign($manifest);

        set_transient(
            'mua_manifest_' . $appId,
            ['manifest' => $manifest, 'signature' => $signature],
            HOUR_IN_SECONDS
        );
    }
}
