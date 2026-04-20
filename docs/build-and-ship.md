# Build and Ship

What happens between clicking **Ship It!** and Bifrost producing a
binary. This page covers the projection pipeline, the blueprint, the
manifest signing contract, and the env vars Bifrost needs.

## Ship It flow

```
User clicks Ship It in the App edit screen
  ↓
ShipRoute (POST /apps/{id}/ship)
  ↓ ManifestBuilder.build($app)
  ↓ BuildAssembler.assembleBase(app, manifestJson, blueprintPath, buildPath)
  ↓     copyDirectory(blueprint → buildPath)
  ↓     injectManifest(json + sig → storage/app/)
  ↓     projectComponents (every block's mobile.blade.php → resources/views/components/mustuse/)
  ↓     projectBlockAssets (newest _dist/styles.inline-*.css → public/blocks/{slug}/)
  ↓     syncAssets (icon + splash; synthesises GD placeholder if no media set)
  ↓     composeEnvExample (.env.example with non-secret defaults)
  ↓     composeReadme (per-app README with env table)
  ↓ envForBifrost (returned to caller for Bifrost paste)
  ↓ as_enqueue_async_action(mua_project_build_to_github)
  ↓ bumpVersionCode (after queue, so a failed assemble keeps the number)
  ↓ HTTP 202 returned with env table

Background:
  ProjectBuildToGithub (Action Scheduler job)
    → GitHub Git Data API (no shell_exec, no local git)
    → push branch build/{slug}-{epoch}-{rand}
    → record commit SHA + branch + status on app meta
```

The user then opens the Bifrost project for that app, picks the new
branch, and clicks Build. Bifrost does `composer install --no-dev && npm
run build` and produces signed binaries.

## The blueprint

`assets/blueprints/mobile-shell/` is a complete Laravel 12 + Livewire +
NativePHP v3 project that gets copied verbatim on every Ship It.
Highlights:

- `app/Livewire/NativeEdge.php` — the catch-all renderer that resolves
  the requested URL against the manifest, walks the screen's block
  tree, and dispatches each block.
- `app/Providers/NativeAppServiceProvider.php` — registers explicit
  per-app capability plugins (NativePHP v3 requires this; Composer
  auto-discovery is intentionally bypassed). Also self-heals a missing
  `APP_KEY` on first boot as a backstop, even though `APP_KEY` is now
  injected stably via Bifrost env.
- `resources/views/livewire/partials/dispatch-block.blade.php` — picks
  the right Blade component (mustuse, dynamic, query-loop, core
  fallback) for each block in the tree.
- `resources/views/livewire/partials/core-block.blade.php` — minimal
  HTML renderer for `core/*` blocks (heading, paragraph, list, image,
  etc.) so author content from the WP editor shows on device.
- `config/nativephp.php` — the v3 config with persistent runtime
  enabled, orientation, permissions, and `cleanup_env_keys` (which
  strips `MUA_APPKEY`, `APP_KEY`, AWS / GitHub creds, and
  `*_SECRET` patterns from the bundle).
- `.gitignore` — includes `/nativephp`, `/public/ios-hot`,
  `/public/android-hot` per the v3 docs.

## Manifest signing

[`ManifestSigner`](../src/Support/ManifestSigner.php) HMAC-SHA256 signs
the exact bytes BuildAssembler writes to `storage/app/mua-manifest.json`.
The signature is written alongside as `storage/app/mua-manifest.sig`.

The shell's `NativeEdge::loadManifest` does the same HMAC over the file
bytes with `env('MUA_APPKEY')` and refuses to render on mismatch. When
`MUA_APPKEY` is absent (local dev), verification is skipped.

The signing key lives in the publisher's `wp_options` under
`mua_manifest_signing_key` — generated lazily on first sign, rotatable
via `ManifestSigner::rotateKey()`. Bifrost reads the same value from
the publisher's `envForBifrost` output and injects it as `MUA_APPKEY`.

## Env-var contract

[`BuildAssembler::envForBifrost`](../src/Support/BuildAssembler.php)
returns the full env list Bifrost must inject. Each row is
`{name, value, secret}`. Publisher-defined env vars from `_mua_env_vars`
post meta override auto-generated values.

| Name                          | Source                                          | Secret |
| ----------------------------- | ----------------------------------------------- | ------ |
| `APP_KEY`                     | `_mua_laravel_app_key` (created once per app)   | ✅      |
| `NATIVEPHP_APP_ID`            | `com.mustuse.{slug}`                            |        |
| `NATIVEPHP_APP_VERSION`       | `_mua_app_version_name`                         |        |
| `NATIVEPHP_APP_VERSION_CODE`  | `_mua_app_version_code`                         |        |
| `NATIVEPHP_DEEPLINK_SCHEME`   | `_mua_deeplink_scheme`                          |        |
| `NATIVEPHP_DEEPLINK_HOST`     | `_mua_deeplink_host`                            |        |
| `NATIVEPHP_START_URL`         | always `/`                                      |        |
| `NATIVEPHP_DEVELOPMENT_TEAM`  | `mua_native_development_team` (publisher-wide)  |        |
| `NATIVEPHP_RUNTIME_MODE`      | `_mua_native_runtime_mode` (default `persistent`) |      |
| `MUA_MANIFEST_URL`            | `{site}/wp-json/mustuse-apps-pub/v1/apps/{slug}/manifest` |        |
| `MUA_PUBLISHER_URL`           | `site_url()`                                    |        |
| `MUA_APPKEY`                  | `ManifestSigner::getOrCreateKey()`              | ✅      |

Secrets never appear in the `.env.example` shipped to GitHub; they're
listed by name as a Bifrost paste reference. The blueprint's
`config/nativephp.php` `cleanup_env_keys` array also strips them
defensively if a developer set them locally.

## Asset synthesis

[`BuildAssembler::syncAssets`](../src/Support/BuildAssembler.php) writes
`public/icon.png` (1024²) and `public/splash.png` (1080×1920). Order:

1. If the App post has a featured image (icon) or `mua_splash_screen_id`
   meta (splash), copy that file. Failure to copy a declared media id
   throws — the publisher must fix the broken upload before re-shipping.
2. Otherwise synthesise a branded placeholder via GD using the app's
   primary colour and the first letter of the title. Builds always
   produce a valid icon, even before media is uploaded.

## Build version code

`BuildAssembler::bumpVersionCode($app)` runs after the projection job
is enqueued. A failed assemble or queue call leaves `version_code`
untouched so a publisher can retry without skipping a build number.
Composition (env, README, manifest) is a pure read — the bump is an
explicit step.

## Recovering from a bad ship

A failed projection leaves the buildPath populated under
`wp-upload/mua-builds/{slug}/`. Subsequent Ships overwrite it. The
GitHub branch is appended with an epoch + random suffix
(`build/{slug}-{epoch}-{rand}`) so collisions are impossible.

If a manifest validation error lands in `_mua_last_build_error`,
clear the offending screen / block and re-Ship. The Ship Readiness
metabox would have surfaced the same error before the click — use it.
