# Development

Local setup, the test stack, static analysis, the wp-env integration
harness, releases, and contributing conventions.

## Requirements

|           |                  |
| --------- | ---------------- |
| WordPress | 6.4+             |
| PHP       | 8.2+             |
| Composer  | 2.x (build-time) |
| Node      | 20+ (build-time) |

Action Scheduler ships bundled. Runtime dependencies are pulled in by
Composer and Vite; nothing system-wide is required on the production
host beyond a standard WordPress + PHP stack.

## Local setup

```sh
# Inside your wp-content/plugins directory
git clone https://github.com/webmultipliers/mustuse-apps-pub.git
cd mustuse-apps-pub
composer install         # PHP deps + dev tooling (PHPUnit, PHPStan, Brain\Monkey)
npm install              # JS / build deps
npm run dev              # Vite dev server with HMR
```

The Vite dev server is auto-detected when `dist/hot` exists and
`WP_DEBUG` is on; otherwise the plugin enqueues built assets from
`dist/`. A corrupt or missing `dist/.vite/manifest.json` logs to
`WP_DEBUG_LOG` instead of silently producing an asset-less admin page.

## Testing

```sh
composer test                # PHPUnit unit suite (Brain\Monkey + Mockery)
composer test:js             # Vitest (jsdom)
composer test:integration    # PHPUnit integration suite (wp-env)
composer test:coverage       # Xdebug-driven HTML coverage at coverage/php
composer test:all            # unit + Vitest + PHPStan
```

Coverage today:

- **PHPUnit unit** — PHP unit tests across Admin, Api, Content, Data,
  Jobs, Logging, Manifest, Preview, Routing, and Support. Uses
  Brain\\Monkey for WP function stubs, Mockery for typed doubles.
- **PHPUnit integration** — wp-env-backed, covers `SchemaManager` and
  well-known routing.
- **Vitest** — covers the editorial bundle (tabs-less layout, media
  uploader, ship-it apiFetch, deployment-map poll, screen-tree
  SortableJS hydration, screen-config routing-type toggle) plus the
  preview bundle (splash auto-dismiss, screen switcher).

### WordPress integration harness

```sh
npm run wp-env:start         # start a wp-env container (see .wp-env.json)
composer test:integration
npm run wp-env:stop
```

## Static analysis

```sh
composer analyse                # PHPStan level 8 (with WordPress stubs)
composer analyse:baseline       # regenerate phpstan-baseline.neon
composer lint                   # PHP-CS-Fixer dry-run
composer lint:fix               # apply PHP-CS-Fixer
bash tools/seam-check.sh        # project-specific guard on architectural seams
```

`phpstan-baseline.neon` exists for legacy WordPress glue that doesn't
fit level-8 typing; new code should not add to it.

## Project layout

```
src/
  Admin/             EditorialController · DeploymentRequirements
  Api/
    Editorial/       Ship · BuildStatus · ScreensTree · TestGithub ·
                     DeploymentRequirements
    Middleware/      AppKeyAuth
    Shell/           per-app routes (Manifest, Content, Terms,
                     Capability, Deeplink, Auth ×3, SubscriberState,
                     Bookmarks, History, PushEnroll)
    WellKnown/       AASA + assetlinks (domain root)
  Blocks/
    canvas/          admin canvas blocks
    {slug}/          content blocks (atomic block pattern)
    AllowedBlocks.php · BlockSchemaAuditor.php
  Content/           ContextBuilder · ContentMapper · TermsMapper ·
                     ImageSerializer · DeeplinkResolver ·
                     DeviceStateStore · RouteMapper
  Data/Models/       App · Screen
  Events/            PostLifecycle (manifest cache, cascade delete/trash)
  Extensions/        ExtensionRegistry
  Jobs/              Action Scheduler handlers
  Logging/           ShellAccessLog (with redaction)
  Manifest/          ManifestBuilder · ManifestValidator · BlockValidator ·
                     BlockAttributeNormalizer · CapabilityRegistry
  Preview/           PreviewRoute (front-end dashboard)
  Routing/           ScreenRouteRegistry · ScreenRouteProjector
  Support/           SecureStorage · ManifestSigner · AppKeyManager ·
                     BuildAssembler · GitHubProjector · Vite
  Plugin.php         init orchestration

assets/
  editorial/         App / Screen editor TS + SCSS
  preview/           Preview dashboard TS + SCSS
  shared/            SCSS tokens + base
  blueprints/
    mobile-shell/    Laravel 12 + Livewire + NativePHP v3 blueprint
                     copied on each Ship; block components projected
                     on top of it

views/
  editorial/         settings.php · _deployment-map.php
  preview/           dashboard.php

tests/
  php/Unit/          per-class unit tests
  php/Integration/   wp-env-backed integration tests
  ts/                Vitest + jsdom
  stubs/             test fixtures
```

## Coding standards & invariants

- **Single-publisher, multi-app.** One install = one publisher; that
  publisher has many apps.
- **Standalone projection.** The pub's only outbound dependency is
  GitHub; there is no Hub or control-plane to coordinate with.
- **No SDK.** All sanitisation and API-client logic lives in this repo.
- **Action Scheduler for everything async.** No `wp_schedule_event`.
  No inline outbound HTTP. Group: `mustuse-apps-pub`.
- **Strict UI stack.** Vite + TypeScript (strict) + hand-authored SCSS
  with CSS custom properties. No CSS framework.
- **Block framework.** Blockstudio, folder-drop, namespace
  `mustuse-apps-pub/`. Every block ships `native.json` + `README.md`.
- **Pub never contacts Bifrost directly.** Builds flow through GitHub;
  Bifrost consumes the projected branch.
- **Code-signing creds are never pub-resident.** The pub never holds
  Apple certs, provisioning profiles, Android keystores, App Store
  Connect keys, or Play service-account JSONs.

## Contributing

1. Read [`AGENTS.md`](../AGENTS.md) and the architecture pages in this
   docs directory.
2. Branch from `development`.
3. Add tests alongside the change. PHPUnit for PHP, Vitest for
   TypeScript. Integration tests when crossing the WordPress boundary.
4. Run `composer test:all` before opening a PR.
5. Bump the `Version:` header in `mustuse-apps-pub.php` — the PR
   verifier rejects PRs to `main` without a version bump.
6. Open the PR against `development`. CI must be green.

## Releases

Produced automatically by
[`.github/workflows/release.yml`](../.github/workflows/release.yml):

| Trigger                | Outcome                                               |
| ---------------------- | ----------------------------------------------------- |
| Push to `main`         | Release `v<header-version>` marked latest             |
| Push to `development`  | Pre-release `v<header-version>-development-<sha>`     |
| Push tag `v<version>`  | Release (tag must match header version exactly)       |
| PR to `main`           | Runs tests + enforces version-bump                    |
| Manual dispatch        | Same as push; `force=true` rebuilds an existing tag   |

Every trigger runs PHPUnit + PHPStan + PHP-CS-Fixer + seam-check +
Vitest + `tsc --noEmit` first. If any fail, no release is produced.

The `build_and_release` job runs `composer install --no-dev
--optimize-autoloader` and `npm run build`, then `rsync`s the tree
through [.distignore](../.distignore) into a staging directory and zips
it. The resulting `<slug>.zip` contains `vendor/`, `dist/`, `src/`,
`views/`, `assets/blueprints/`, `uninstall.php` and
`mustuse-apps-pub.php` — no Composer or Node needed on the publisher's
host.

### Required GitHub repo variables

Set these once under **Settings → Secrets and variables → Actions →
Variables**:

| Variable         | Required | Example                   |
| ---------------- | -------- | ------------------------- |
| `PLUGIN_SLUG`    | yes      | `mustuse-apps-pub`        |
| `MAIN_FILE_PATH` | yes      | `mustuse-apps-pub.php`    |
| `PHP_VERSION`    | no       | `8.3` (default)           |
| `NODE_VERSION`   | no       | `20` (default)            |

## Dependencies

**Runtime (PHP):**
`blockstudio/blockstudio ^7.1`, `woocommerce/action-scheduler ^3.7`

**Runtime (JS):**
`sortablejs ^1.15.7`, `@types/sortablejs ^1.15.9`

**Dev:**
PHPUnit 10.5, PHPStan 2.1 + `szepeviktor/phpstan-wordpress`,
Brain\\Monkey, Mockery, PHP-CS-Fixer, Yoast PHPUnit Polyfills,
wp-phpunit 6.4, Vite 5.4, Vitest 2.1, TypeScript 5.4, Sass 1.77,
jsdom 25, `@wordpress/env` 10.
