<?php

declare(strict_types=1);

namespace MustUse\Pub\Support;

use MustUse\Pub\Data\Models\App;

/**
 * Assembles a per-app build directory from the mobile-shell blueprint.
 *
 * Output path layout:
 *   {buildPath}/                ← copied verbatim from the blueprint
 *   {buildPath}/storage/app/mua-manifest.json  ← signed manifest snapshot
 *   {buildPath}/public/icon.png, /splash.png    ← synced from post thumbnails
 *   {buildPath}/.env.example    ← per-app non-secret defaults (overwritten)
 *
 * Secrets are **never** written to the buildPath. Runtime env is delivered
 * via {@see envForBifrost()} so the publisher can paste it into Bifrost's
 * Environment Variables UI.
 */
class BuildAssembler {
	public function assembleBase( App $app, string $manifestJson, string $blueprintPath, string $buildPath ): void {
		if ( \is_dir( $buildPath ) ) {
			$this->deleteDirectory( $buildPath );
		}
		$this->copyDirectory( $blueprintPath, $buildPath );

		$this->injectManifest( $manifestJson, $buildPath );
		$this->projectComponents( $buildPath );
		$this->projectBlockAssets( $buildPath );
		$this->syncAssets( $app, $buildPath );
		$this->composeEnvExample( $app, $buildPath );
		$this->composeReadme( $app, $buildPath );

		// Audit on every build — findings are logged; strict mode (set via
		// MUA_STRICT_BLOCK_AUDIT env) throws so CI can block a broken ship.
		if ( \class_exists( \MustUse\Pub\Blocks\BlockSchemaAuditor::class) ) {
			\MustUse\Pub\Blocks\BlockSchemaAuditor::audit( $this->blockSources() );
		}
	}

	/**
	 * Generate a first-draft README.md in the projected build path. The
	 * reader audience is split: (a) publishers who open the GitHub repo to
	 * confirm what was projected; (b) whoever configures Bifrost; (c)
	 * anyone tempted to edit the repo directly (they should not — it's
	 * overwritten on next ship).
	 *
	 * All env rows from envForBifrost() are rendered verbatim, with secrets
	 * masked. A human-readable timestamp + branch name tie the projection
	 * back to a specific Ship It click.
	 */
	public function composeReadme( App $app, string $buildPath ): void {
		$versionName   = (string) $app->meta( 'app_version_name', '1.0.0' );
		$versionCode   = (int) $app->meta( 'app_version_code', 1 );
		$timestamp     = \gmdate( 'Y-m-d H:i \U\T\C' );
		$slug          = $app->slug();
		$title         = $app->title();
		$publisherUrl  = site_url();
		$editorUrl     = $publisherUrl . '/wp-admin/post.php?post=' . $app->id() . '&action=edit';
		$envForBifrost = $this->envForBifrost( $app );

		$envTableRows = [];
		foreach ( $envForBifrost as $row ) {
			$value          = $row['secret']
				? '`(secret — see Bifrost env UI)`'
				: ( $row['value'] === '' ? '_(empty — set in app editor)_' : '`' . \str_replace( '|', '\\|', $row['value'] ) . '`' );
			$flag           = $row['secret'] ? ' 🔒' : '';
			$envTableRows[] = '| `' . $row['name'] . '`' . $flag . ' | ' . $value . ' |';
		}
		$envTable = \implode( "\n", $envTableRows );

		$readme = <<<MD
# {$title}

> **⚠️ Auto-generated — do not edit.** This repository is projected from the MustUse Apps Publisher on every **Ship It!** click. Manual edits will be overwritten on the next projection. Make changes in the [Publisher admin]({$editorUrl}) instead.

This is a self-contained mobile app shell. It boots Laravel, hydrates from a signed manifest, and renders screens defined in WordPress as native iOS / Android UI via [NativePHP for Mobile v3](https://nativephp.com/).

| Field | Value |
|---|---|
| App name | {$title} |
| Slug | `{$slug}` |
| Bundle id | `com.mustuse.{$slug}` |
| Version | `{$versionName}` (build `{$versionCode}`) |
| Projected at | {$timestamp} |
| Publisher | {$publisherUrl} |

## What's in this repo

```
app/                     Laravel application (Livewire components, providers)
config/nativephp.php     iOS / Android shell config (orientation, permissions)
public/icon.png          1024×1024 app icon (synced from the App's featured image)
public/splash.png        1080×1920 splash screen (synced from the App's splash meta)
resources/views/
  components/mustuse/    Block components projected from the publisher's plugin
  livewire/              The catch-all renderer that drives every screen
storage/app/
  mua-manifest.json      The signed manifest the shell renders at runtime
  mua-manifest.sig       HMAC-SHA256 signature over the manifest bytes
.env.example             Reference; Bifrost injects the real values
```

## Stack

[Laravel 12](https://laravel.com/) · [Livewire 3.5](https://livewire.laravel.com/) · [NativePHP Mobile 3.0](https://nativephp.com/) · PHP 8.2+

## Bifrost handoff

Bifrost has no API, so this is one-time setup per app. **All secrets live in Bifrost — they are not in this repo and they are not in WordPress.**

1. Open [bifrost.nativephp.com](https://bifrost.nativephp.com) and create a Project (or open the one already linked to this app).
2. Connect this repository via the Bifrost GitHub App.
3. Upload iOS + Android signing credentials under **Credentials** (one-time per platform):
   - iOS: an Apple Developer account, an iOS Distribution certificate, a Provisioning Profile, and an App Store Connect API key for upload.
   - Android: an upload keystore (`.jks` / `.p12`) and the password.
4. Paste the environment variables below into the Project's **Environment Variables** screen. Rows marked 🔒 are secrets — copy them from the Publisher admin panel and never commit them here.
5. Select the projected build branch and click **Ship It!** in Bifrost.

### Environment variables

| Name | Value |
|---|---|
{$envTable}

## Manifest contract

The manifest is the runtime contract between WordPress and the shell. On boot:

1. The Livewire `NativeEdge` component reads `storage/app/mua-manifest.json`.
2. It HMAC-verifies the bytes against `storage/app/mua-manifest.sig` using `MUA_APPKEY` — fail-closed when the key is configured. A signature mismatch refuses to render.
3. It resolves the requested screen by path, walks the screen's block tree, and dispatches each block to a Blade component under `resources/views/components/mustuse/`.

The shell never embeds business logic. New screens, copy changes, branding tweaks, even new top-level navigation tabs — all flow through WordPress and the manifest. You typically only re-Ship when you've shipped a new block type or upgraded the NativePHP version.

## NativePHP assets

NativePHP looks for these in `public/`:

- **`public/icon.png`** — 1024 × 1024 PNG, no transparency. Synced from the App's WordPress featured image.
- **`public/splash.png`** — 1080 × 1920 PNG (light mode). Synced from the App's splash-screen attachment.
- **`public/splash-dark.png`** — 1080 × 1920 PNG (dark mode). Optional; not currently synced.

If `public/icon.png` is missing or empty, drop a 1024 × 1024 PNG into the WordPress App's featured image and re-Ship.

## Local development

```bash
composer install
npm install
npm run build
```

For interactive iOS / Android development (requires Xcode on macOS or Android Studio):

```bash
php artisan native:install      # one-time
php artisan native:run          # boot the simulator/emulator
```

For iOS hot-reload via the Jump app:

```bash
php artisan native:jump
```

Then scan the QR code with the NativePHP Jump iOS app on a physical device.

## How this repo gets regenerated

Every **Ship It!** click in the Publisher:

1. Copies the `mobile-shell` blueprint into a temp directory.
2. Projects every block's `mobile.blade.php` into `resources/views/components/mustuse/`.
3. Writes the signed manifest to `storage/app/mua-manifest.{json,sig}`.
4. Syncs `public/icon.png` + `public/splash.png` from the App's media.
5. Composes `.env.example` + this README.
6. Pushes everything as a new branch via the GitHub Git Data API (no `shell_exec`, no local git).
7. Records change-detection hash so identical projections become no-ops on subsequent ships.

The publisher then opens a PR (or auto-merges, depending on workflow). Bifrost picks up the branch, runs `composer install --no-dev && npm run build`, signs, and ships to the app stores.

---

_Edit this app: {$editorUrl}_
_Generated by [MustUse Apps Publisher](https://github.com/webmultipliers) on {$timestamp}._
MD;

		\file_put_contents( $buildPath . '/README.md', $readme );
	}


	/**
	 * Atomic Block pattern: each block folder in src/Blocks/
	 * owns its full lifecycle — block.json for the editor, native.json for
	 * the manifest, and mobile.blade.php for the shell. This method walks
	 * src/Blocks/ and projects every mobile template into the shell at
	 * resources/views/components/mustuse/{slug}.blade.php.
	 *
	 * Blueprint ships with zero UI components; the shell's personality is
	 * entirely inflated at build time from the blocks the pub ships with.
	 *
	 * @return list<string> Slugs projected (mainly for logging/tests)
	 */
	public function projectComponents( string $buildPath ): array {
		$targetDir = $buildPath . '/resources/views/components/mustuse';
		if ( ! \is_dir( $targetDir ) && ! @\mkdir( $targetDir, 0755, true ) && ! \is_dir( $targetDir ) ) {
			throw new \RuntimeException( "Failed to create component projection directory: {$targetDir}" );
		}

		$projected = [];
		foreach ( $this->blockSources() as $slug => $sourceDir ) {
			$mobile = $sourceDir . '/mobile.blade.php';
			if ( ! \is_file( $mobile ) ) {
				continue;
			}
			if ( ! \preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
				continue;
			}
			\copy( $mobile, $targetDir . '/' . $slug . '.blade.php' );
			$projected[] = $slug;
		}
		return $projected;
	}

	/**
	 * Copy per-block built assets from Blockstudio's `_dist/` directory
	 * into the shell at `public/blocks/{slug}/` so the mobile shell can
	 * inline the exact same compiled bytes the WP editor/frontend uses
	 * (one source of truth per block; no parallel asset pipeline to
	 * maintain).
	 *
	 * Blockstudio emits timestamped filenames — `styles.inline-{ts}.css`,
	 * `styles-{ts}.css`, `scripts-{ts}.js`, etc — and keeps older builds
	 * in `_dist/` across rebuilds. We pick the newest file per logical
	 * variant and rename it to a stable name at the target so the shell's
	 * layout can reference e.g. `/blocks/{slug}/styles.inline.css` without
	 * knowing the latest timestamp.
	 *
	 * @return list<string> Slugs whose _dist/ contributed at least one asset.
	 */
	public function projectBlockAssets( string $buildPath ): array {
		$projected = [];
		foreach ( $this->blockSources() as $slug => $sourceDir ) {
			if ( ! \preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
				continue;
			}
			$target = $buildPath . '/public/blocks/' . $slug;
			if ( ! \is_dir( $target ) && ! @\mkdir( $target, 0755, true ) && ! \is_dir( $target ) ) {
				continue;
			}

			$gotAnything = false;

			$dist = $sourceDir . '/_dist';
			if ( \is_dir( $dist ) ) {
				$gotAnything = $this->copyNewestBlockAssets( $dist, $target );
			}

			// Project native.json next to the compiled assets. The shell's
			// dispatch-block layer reads it at render time to decide whether
			// the block is dynamic (`dataSource`), context-bound
			// (`consumes_context`), or plain static — one file, three
			// consumers (WP editor, WP front, mobile shell).
			$nativeJson = $sourceDir . '/native.json';
			if ( \is_file( $nativeJson ) && @\copy( $nativeJson, $target . '/native.json' ) ) {
				$gotAnything = true;
			}

			if ( $gotAnything ) {
				$projected[] = $slug;
			}
		}
		return $projected;
	}

	/**
	 * Each logical asset variant (`styles.inline`, `styles`, `scripts`,
	 * `scripts.inline`, `scripts.view`) can have many timestamped
	 * compilations lying around in `_dist/`. Pick the newest per variant
	 * and copy it to `{target}/{variant}.{ext}` (stable name).
	 *
	 * Returns true if at least one asset was copied.
	 */
	private function copyNewestBlockAssets( string $distDir, string $targetDir ): bool {
		// Group `{variant}-{timestamp}.{ext}` files by `{variant}.{ext}`
		// and keep the newest mtime per group.
		$candidates = ( \glob( $distDir . '/*.css' ) ?: [] );
		$candidates = \array_merge( $candidates, ( \glob( $distDir . '/*.js' ) ?: [] ) );

		$latest = [];
		foreach ( $candidates as $absPath ) {
			$basename = \basename( $absPath );
			if ( ! \preg_match( '/^(?P<variant>[a-z0-9.\-]+?)-(?P<ts>\d+)\.(?P<ext>css|js)$/', $basename, $m ) ) {
				continue;
			}
			$stableName = $m['variant'] . '.' . $m['ext'];
			$ts         = (int) $m['ts'];
			if ( ! isset( $latest[ $stableName ] ) || $ts > $latest[ $stableName ]['ts'] ) {
				$latest[ $stableName ] = [ 'src' => $absPath, 'ts' => $ts ];
			}
		}

		$copied = 0;
		foreach ( $latest as $stableName => $info ) {
			if ( @\copy( $info['src'], $targetDir . '/' . $stableName ) ) {
				$copied++;
			}
		}
		return $copied > 0;
	}

	/**
	 * Resolve the full set of blocks to project. Returns a map of
	 * `slug => absolute source dir`. Starts with blocks under our own
	 * `src/Blocks/` (content blocks only — canvas/* is excluded) and then
	 * invites extensions to contribute via the `mua_block_sources` filter
	 * so a third-party plugin can ship blocks with matching mobile twins
	 * without forking BuildAssembler.
	 *
	 * @return array<string, string>
	 */
	public function blockSources(): array {
		$sources  = [];
		$rootDir  = $this->blocksDirectory();
		$children = \is_dir( $rootDir ) ? ( \scandir( $rootDir ) ?: [] ) : [];
		foreach ( $children as $child ) {
			if ( $child === '.' || $child === '..' || $child === 'canvas' ) {
				continue;
			}
			$childPath = $rootDir . '/' . $child;
			if ( ! \is_dir( $childPath ) || ! \is_file( $childPath . '/block.json' ) ) {
				continue;
			}
			$sources[ $child ] = $childPath;
		}

		/** @see mua_block_sources filter — extensions push `slug => absolute_dir` entries here. */
		$filtered = \function_exists( 'apply_filters' )
			? apply_filters( 'mua_block_sources', $sources )
			: $sources;
		return \is_array( $filtered ) ? $filtered : $sources;
	}

	/**
	 * Overridable so tests can point projection at a fixture directory.
	 */
	protected function blocksDirectory(): string {
		if ( \defined( 'MUA_PUB_DIR' ) ) {
			return \rtrim( (string) \MUA_PUB_DIR, '/' ) . '/src/Blocks';
		}
		return \dirname( __DIR__, 2 ) . '/src/Blocks';
	}

	/**
	 * Generate (or read) a stable Laravel APP_KEY for this app.
	 *
	 * Stored in app meta so subsequent Ships emit the same value — losing
	 * the key would invalidate every encrypted cookie/session on every
	 * device already running the app, and force users to re-authenticate.
	 * Format matches `php artisan key:generate` output (`base64:` prefix
	 * + 32 random bytes, base64-encoded) so it slots straight into
	 * Laravel's encrypter without a runtime conversion.
	 */
	private function getOrCreateLaravelAppKey( App $app ): string {
		$existing = (string) $app->meta( 'laravel_app_key', '' );
		if ( $existing !== '' ) {
			return $existing;
		}

		$generated = 'base64:' . \base64_encode( \random_bytes( 32 ) );
		$app->updateMeta( 'laravel_app_key', $generated );
		return $generated;
	}

	/**
	 * Returns the full env var list the publisher must configure in Bifrost.
	 *
	 * Contract: each row is `{name, value, secret}`. Publisher-defined env
	 * vars from `_mua_env_vars` post meta override auto-generated values
	 * with the same name, and new names append.
	 *
	 * @return list<array{name: string, value: string, secret: bool}>
	 */
	public function envForBifrost( App $app ): array {
		$versionName    = (string) $app->meta( 'app_version_name', '1.0.0' );
		$versionCode    = (int) $app->meta( 'app_version_code', 1 );
		$deeplinkScheme = (string) $app->meta( 'deeplink_scheme', '' );
		$deeplinkHost   = (string) $app->meta( 'deeplink_host', '' );
		$developmentTeam = (string) ( get_option( 'mua_native_development_team', '' ) ?: '' );
		$runtimeMode    = (string) $app->meta( 'native_runtime_mode', 'persistent' );

		$auto = [
			// APP_KEY is a per-app Laravel encryption key — generated once
			// and persisted in app meta so every Ship reuses the same value.
			// Without a stable key the shell's NativeAppServiceProvider falls
			// back to per-device generation, which breaks session/cookie
			// continuity across reinstalls and update channels.
			[ 'name' => 'APP_KEY', 'value' => $this->getOrCreateLaravelAppKey( $app ), 'secret' => true ],
			[ 'name' => 'NATIVEPHP_APP_ID', 'value' => 'com.mustuse.' . $app->slug(), 'secret' => false ],
			[ 'name' => 'NATIVEPHP_APP_VERSION', 'value' => $versionName !== '' ? $versionName : '1.0.0', 'secret' => false ],
			[ 'name' => 'NATIVEPHP_APP_VERSION_CODE', 'value' => (string) $versionCode, 'secret' => false ],
			[ 'name' => 'NATIVEPHP_DEEPLINK_SCHEME', 'value' => $deeplinkScheme, 'secret' => false ],
			[ 'name' => 'NATIVEPHP_DEEPLINK_HOST', 'value' => $deeplinkHost, 'secret' => false ],
			[ 'name' => 'NATIVEPHP_START_URL', 'value' => '/', 'secret' => false ],
			// iOS code-signing — Apple Developer Team ID, configured publisher-wide
			// because every projected app ships under the same Apple account.
			[ 'name' => 'NATIVEPHP_DEVELOPMENT_TEAM', 'value' => $developmentTeam, 'secret' => false ],
			// v3.1+ persistent runtime — keeps Laravel kernel alive between
			// requests for ~10× lower latency. `classic` falls back to a
			// fresh boot per dispatch.
			[ 'name' => 'NATIVEPHP_RUNTIME_MODE', 'value' => \in_array( $runtimeMode, [ 'persistent', 'classic' ], true ) ? $runtimeMode : 'persistent', 'secret' => false ],
			[ 'name' => 'MUA_MANIFEST_URL', 'value' => get_rest_url( NULL, 'mustuse-apps-pub/v1/apps/' . $app->slug() . '/manifest' ), 'secret' => false ],
			[ 'name' => 'MUA_PUBLISHER_URL', 'value' => site_url(), 'secret' => false ],
			[ 'name' => 'MUA_APPKEY', 'value' => ManifestSigner::getOrCreateKey(), 'secret' => true ],
		];

		$byName = [];
		foreach ( $auto as $row ) {
			$byName[ $row['name'] ] = $row;
		}

		$publisher = $app->meta( 'env_vars', [] );
		if ( \is_array( $publisher ) ) {
			foreach ( $publisher as $row ) {
				if ( ! \is_array( $row ) || ! isset( $row['name'] ) || ! \is_string( $row['name'] ) || $row['name'] === '' ) {
					continue;
				}
				$byName[ $row['name'] ] = [
					'name'   => $row['name'],
					'value'  => (string) ( $row['value'] ?? '' ),
					'secret' => ! empty( $row['secret'] ),
				];
			}
		}

		return \array_values( $byName );
	}

	/**
	 * Writes `.env.example` to the build path with per-app non-secret defaults.
	 *
	 * This file is committed to the projected repo as a reference for what
	 * environment variables Bifrost must be configured to inject at build
	 * time. Secrets (MUA_APPKEY, APP_KEY, push creds) are intentionally
	 * omitted — they belong in Bifrost's env UI, not in source control.
	 */
	public function composeEnvExample( App $app, string $buildPath ): void {
		$versionName = (string) $app->meta( 'app_version_name', '1.0.0' );
		$versionCode = (int) $app->meta( 'app_version_code', 1 );

		$deeplinkScheme = (string) $app->meta( 'deeplink_scheme', '' );
		$deeplinkHost   = (string) $app->meta( 'deeplink_host', '' );

		$lines = [
			'# Generated by MustUse Apps Publisher at ' . \gmdate( 'c' ),
			'# Bifrost injects runtime values; this file is a reference only.',
			'',
			'APP_NAME=Laravel',
			'APP_ENV=production',
			'# APP_KEY is a per-app secret — Bifrost injects it from envForBifrost.',
			'APP_KEY=',
			'APP_DEBUG=false',
			'APP_URL=http://localhost',
			'',
			'LOG_CHANNEL=stack',
			'LOG_LEVEL=warning',
			'',
			'DB_CONNECTION=sqlite',
			'SESSION_DRIVER=file',
			'CACHE_STORE=file',
			'QUEUE_CONNECTION=sync',
			'BROADCAST_CONNECTION=log',
			'FILESYSTEM_DISK=local',
			'',
			'# NativePHP',
			'NATIVEPHP_APP_ID=com.mustuse.' . $app->slug(),
			'NATIVEPHP_APP_VERSION=' . ( $versionName !== '' ? $versionName : '1.0.0' ),
			'NATIVEPHP_APP_VERSION_CODE=' . $versionCode,
			'NATIVEPHP_DEEPLINK_SCHEME=' . $deeplinkScheme,
			'NATIVEPHP_DEEPLINK_HOST=' . $deeplinkHost,
			'NATIVEPHP_START_URL=/',
			'# Apple Developer Team ID — required for iOS code signing.',
			'NATIVEPHP_DEVELOPMENT_TEAM=',
			'# v3.1+ persistent runtime keeps the Laravel kernel alive between requests.',
			'NATIVEPHP_RUNTIME_MODE=persistent',
			'',
			'# MustUse Apps (pub boot vars)',
			'MUA_MANIFEST_URL=' . get_rest_url( NULL, 'mustuse-apps-pub/v1/apps/' . $app->slug() . '/manifest' ),
			'MUA_PUBLISHER_URL=' . site_url(),
			'',
			'# Secret — paste into Bifrost Environment Variables, do not commit:',
			'# MUA_APPKEY=',
		];

		\file_put_contents( $buildPath . '/.env.example', \implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Bumps the app's `app_version_code` meta by one.
	 *
	 * Lifted out of `composeEnvExample` so projection composition is a pure
	 * read of meta — every consumer (env, README, manifest) sees the same
	 * version_code while the ship is being assembled, and the bump is an
	 * explicit step the orchestrator runs once the build is safely queued.
	 */
	public function bumpVersionCode( App $app ): int {
		$current = (int) $app->meta( 'app_version_code', 1 );
		$next    = $current + 1;
		$app->updateMeta( 'app_version_code', $next );
		return $next;
	}

	public function injectManifest( string $manifestJson, string $buildPath ): void {
		$storagePath = $buildPath . '/storage/app';
		if ( ! \is_dir( $storagePath ) ) {
			\mkdir( $storagePath, 0755, true );
		}

		// Sign the EXACT bytes we wrote so the shell's file_get_contents +
		// hash_hmac round-trip matches. Shell verifies with env('MUA_APPKEY')
		// which is populated from the same signing key via envForBifrost().
		$signature = ManifestSigner::signRaw( $manifestJson );

		// Single source of truth — `storage/app/` is part of the app bundle
		// extracted on first boot per the NativePHP v3 lifecycle, so the
		// shell can rely on this exact pair existing without a repo-root
		// fallback. Earlier versions wrote a duplicate at base_path() to
		// guard against an extraction quirk that has since been fixed.
		\file_put_contents( $storagePath . '/mua-manifest.json', $manifestJson );
		\file_put_contents( $storagePath . '/mua-manifest.sig', $signature );
	}

	/**
	 * Writes `public/icon.png` (1024²) and `public/splash.png` (1080×1920)
	 * into the build path.
	 *
	 * Precedence is media-first, synthesis second — so a publisher with a
	 * featured image on the App post always wins, but an app without any
	 * media still produces a valid IPA/APK instead of a native toolchain
	 * error. Failures on a declared media ID are fatal; we'd rather fail
	 * the Ship than silently mask a misconfigured media library.
	 */
	public function syncAssets( App $app, string $buildPath ): void {
		$publicDir = $buildPath . '/public';
		if ( ! \is_dir( $publicDir ) && ! @\mkdir( $publicDir, 0755, true ) && ! \is_dir( $publicDir ) ) {
			throw new \RuntimeException( "Failed to create public directory: {$publicDir}" );
		}

		$this->syncOrSynthesiseIcon( $app, $publicDir . '/icon.png' );
		$this->syncOrSynthesiseSplash( $app, $publicDir . '/splash.png' );
	}

	private function syncOrSynthesiseIcon( App $app, string $target ): void {
		$iconId     = get_post_thumbnail_id( $app->id() );
		$sourcePath = $iconId ? get_attached_file( $iconId ) : '';

		if ( \is_string( $sourcePath ) && $sourcePath !== '' && \is_file( $sourcePath ) ) {
			if ( ! @\copy( $sourcePath, $target ) ) {
				throw new \RuntimeException(
					"Failed to copy app icon from {$sourcePath} to {$target}. Check file permissions on the WP uploads directory."
				);
			}
			return;
		}

		if ( $iconId ) {
			// A featured image is attached but the file on disk is gone —
			// a broken media library almost certainly means more than one
			// asset will be missing, so fail loudly instead of papering over.
			throw new \RuntimeException( \sprintf(
				'App %s declares featured image %d but the file is missing from uploads. Fix the media library before re-shipping.',
				$app->slug(),
				(int) $iconId
			) );
		}

		\file_put_contents( $target, $this->synthesisePlaceholderPng( $app, 1024, 1024, 96 ) );
	}

	private function syncOrSynthesiseSplash( App $app, string $target ): void {
		$splashId   = (int) get_post_meta( $app->id(), 'mua_splash_screen_id', true );
		$sourcePath = $splashId ? get_attached_file( $splashId ) : '';

		if ( \is_string( $sourcePath ) && $sourcePath !== '' && \is_file( $sourcePath ) ) {
			if ( ! @\copy( $sourcePath, $target ) ) {
				throw new \RuntimeException(
					"Failed to copy splash image from {$sourcePath} to {$target}. Check file permissions on the WP uploads directory."
				);
			}
			return;
		}

		if ( $splashId > 0 ) {
			throw new \RuntimeException( \sprintf(
				'App %s declares splash image %d but the file is missing from uploads.',
				$app->slug(),
				$splashId
			) );
		}

		\file_put_contents( $target, $this->synthesisePlaceholderPng( $app, 1080, 1920, 200 ) );
	}

	/**
	 * Generate a solid-colour PNG with the app's first letter centred.
	 * Uses GD (bundled with every PHP 8+ distribution WordPress supports),
	 * colour pulled from `branding.primary_color` so the placeholder at
	 * least carries the publisher's palette.
	 *
	 * Dimensions are caller-chosen non-zero integers (1024² / 1080×1920);
	 * the narrow phpdoc types keep GD's positive-int contract intact
	 * without a runtime guard.
	 *
	 * @param int<1, max> $width
	 * @param int<1, max> $height
	 * @param int<1, max> $fontPx
	 */
	private function synthesisePlaceholderPng( App $app, int $width, int $height, int $fontPx ): string {
		if ( ! \function_exists( 'imagecreatetruecolor' ) ) {
			throw new \RuntimeException( 'GD extension required to synthesise default app icon/splash. Install php-gd or provide a featured image on the App post.' );
		}

		$branding = \is_array( $b = $app->meta( 'branding' ) ) ? $b : [];
		[ $r, $g, $b ] = $this->hexToRgb( (string) ( $branding['primary_color'] ?? '#1e1e1e' ) );

		$img = imagecreatetruecolor( $width, $height );
		if ( $img === false ) {
			throw new \RuntimeException( 'GD failed to allocate placeholder canvas.' );
		}
		imagesavealpha( $img, false );

		$bg = imagecolorallocate( $img, $r, $g, $b );
		$fg = imagecolorallocate( $img, 255, 255, 255 );
		if ( $bg === false || $fg === false ) {
			imagedestroy( $img );
			throw new \RuntimeException( 'GD failed to allocate placeholder colour.' );
		}

		imagefill( $img, 0, 0, $bg );

		$char = \strtoupper( \mb_substr( \trim( $app->title() ), 0, 1 ) ?: 'A' );

		// Built-in font 5 is ~9×15px; scale the glyph visually with
		// imagestringup-style tile repetition so 1024² isn't dominated by
		// empty space. Cheap and dependency-free.
		$tile   = \max( 1, (int) \round( $fontPx / 15 ) );
		$glyphW = 9 * $tile;
		$glyphH = 15 * $tile;
		$tx     = (int) ( ( $width - $glyphW ) / 2 );
		$ty     = (int) ( ( $height - $glyphH ) / 2 );
		for ( $dx = 0; $dx < $tile; $dx++ ) {
			for ( $dy = 0; $dy < $tile; $dy++ ) {
				imagestring( $img, 5, $tx + $dx, $ty + $dy, $char, $fg );
			}
		}

		\ob_start();
		imagepng( $img );
		$png = (string) \ob_get_clean();
		imagedestroy( $img );
		return $png;
	}

	/**
	 * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
	 */
	private function hexToRgb( string $hex ): array {
		$hex = \ltrim( $hex, '#' );
		if ( \strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! \preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return [ 30, 30, 30 ];
		}
		// `hexdec` on a 2-char hex literal is 0-255 by construction; the
		// max/min sandwich is there to convince phpstan of the range
		// rather than guard against a real out-of-band value.
		return [
			\max( 0, \min( 255, (int) \hexdec( \substr( $hex, 0, 2 ) ) ) ),
			\max( 0, \min( 255, (int) \hexdec( \substr( $hex, 2, 2 ) ) ) ),
			\max( 0, \min( 255, (int) \hexdec( \substr( $hex, 4, 2 ) ) ) ),
		];
	}

	public function deleteDirectory( string $dir ): bool {
		if ( ! \file_exists( $dir ) ) {
			return true;
		}
		if ( ! \is_dir( $dir ) ) {
			return \unlink( $dir );
		}

		$files = \scandir( $dir );
		if ( $files !== false ) {
			foreach ( $files as $item ) {
				if ( $item === '.' || $item === '..' ) {
					continue;
				}
				if ( ! $this->deleteDirectory( $dir . \DIRECTORY_SEPARATOR . $item ) ) {
					return false;
				}
			}
		}
		return \rmdir( $dir );
	}

	public function copyDirectory( string $src, string $dst ): void {
		$dir = \opendir( $src );
		if ( $dir === false ) {
			return;
		}

		@\mkdir( $dst, 0755, true );
		while ( false !== ( $file = \readdir( $dir ) ) ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}
			if ( \is_dir( $src . '/' . $file ) ) {
				$this->copyDirectory( $src . '/' . $file, $dst . '/' . $file );
			} else {
				\copy( $src . '/' . $file, $dst . '/' . $file );
			}
		}
		\closedir( $dir );
	}
}
