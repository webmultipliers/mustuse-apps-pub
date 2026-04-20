<?php

declare(strict_types=1);

namespace MustUse\Pub\Support;

/**
 * Resolves Vite-built assets for WordPress enqueuing.
 *
 * In development (WP_DEBUG + hot file present): loads from the Vite dev
 * server with HMR. In production: reads the Vite manifest to resolve
 * hashed filenames.
 */
final class Vite {
    /** @var string[] Script handles that require type="module". */
    private static array $moduleHandles = [];

    private const DEV_SERVER    = 'http://localhost:5173';
    private const MANIFEST_PATH = 'dist/.vite/manifest.json';

    public static function enqueue( string $entry ): void {
        if ( self::isDevServer() ) {
            self::enqueueFromDevServer( $entry );
        } else {
            self::enqueueFromManifest( $entry );
        }
    }

    public static function addModuleType( string $tag, string $handle ): string {
        if ( \in_array( $handle, self::$moduleHandles, true ) ) {
            $tag = \str_replace( ' src=', ' type="module" src=', $tag );
        }
        return $tag;
    }

    private static function isDevServer(): bool {
        return \defined( 'WP_DEBUG' )
            && WP_DEBUG
            && \file_exists( MUA_PUB_DIR . 'dist/hot' );
    }

    private static function enqueueFromDevServer( string $entry ): void {
        $handle = "mua-{$entry}";

        wp_enqueue_script(
            "{$handle}-vite-client",
            self::DEV_SERVER . '/@vite/client',
            [],
            NULL,
            true
        );
        self::$moduleHandles[] = "{$handle}-vite-client";

        wp_enqueue_script(
            $handle,
            self::DEV_SERVER . "/assets/{$entry}/main.ts",
            [ "{$handle}-vite-client" ],
            NULL,
            true
        );
        self::$moduleHandles[] = $handle;

        if ( ! has_filter( 'script_loader_tag', [ self::class, 'addModuleType' ] ) ) {
            add_filter( 'script_loader_tag', [ self::class, 'addModuleType' ], 10, 2 );
        }
    }

    private static function enqueueFromManifest( string $entry ): void {
        $manifestFile = MUA_PUB_DIR . self::MANIFEST_PATH;

        if ( ! \file_exists( $manifestFile ) ) {
            return;
        }

        $manifest = \json_decode( (string) \file_get_contents( $manifestFile ), true );
        if ( ! \is_array( $manifest ) ) {
            // Corrupt or empty Vite manifest — refuse to enqueue so the
            // failure is visible in WP_DEBUG_LOG rather than silently
            // producing a page with missing assets.
            if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                \error_log( \sprintf(
                    '[MUA Vite] Unable to parse manifest at %s; skipping enqueue of "%s".',
                    $manifestFile,
                    $entry
                ) );
            }
            return;
        }

        $entryKey = "assets/{$entry}/main.ts";

        if ( isset( $manifest[ $entryKey ] ) ) {
            $entryData = $manifest[ $entryKey ];
            $handle    = "mua-{$entry}";

            if ( ! empty( $entryData['css'] ) ) {
                foreach ( $entryData['css'] as $i => $cssFile ) {
                    wp_enqueue_style(
                        "{$handle}-css-{$i}",
                        MUA_PUB_URL . 'dist/' . $cssFile,
                        [],
                        self::assetVersion( MUA_PUB_DIR . 'dist/' . $cssFile )
                    );
                }
            }

            if ( ! empty( $entryData['file'] ) ) {
                wp_enqueue_script(
                    $handle,
                    MUA_PUB_URL . 'dist/' . $entryData['file'],
                    [],
                    self::assetVersion( MUA_PUB_DIR . 'dist/' . $entryData['file'] ),
                    true
                );
            }
        }

        // Explicitly enqueue standalone CSS files.
        $cssEntryKey = "assets/{$entry}/main.scss";
        if ( isset( $manifest[ $cssEntryKey ] ) ) {
            $cssFile = $manifest[ $cssEntryKey ]['file'] ?? '';
            if ( $cssFile ) {
                $path = MUA_PUB_DIR . 'dist/' . $cssFile;
                wp_enqueue_style(
                    "mua-{$entry}-style",
                    MUA_PUB_URL . 'dist/css/' . \basename( $cssFile ),
                    [],
                    self::assetVersion( $path )
                );
            }
        }
    }

    /**
     * Cache-bust built assets by their on-disk mtime, falling back to
     * MUA_PUB_VERSION if the file is missing. `MUA_PUB_VERSION` alone is
     * stable across rebuilds, so browsers (and any CDN in front of the
     * site) keep serving the old chunk after a deploy — visible on
     * production as "the fix isn't there" even though the file changed.
     */
    private static function assetVersion( string $path ): string {
        $mtime = @\filemtime( $path );
        if ( $mtime === false ) {
            return MUA_PUB_VERSION;
        }
        return MUA_PUB_VERSION . '.' . $mtime;
    }
}
