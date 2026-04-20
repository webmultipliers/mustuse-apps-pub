# MustUse Apps — Publisher

Turn any WordPress site into a native iOS / Android app, authored from
the block editor.

The Publisher plugin (`mustuse-apps-pub`) lets a WordPress publisher
manage the apps they ship, design screens with familiar Gutenberg
blocks, and project a ready-to-build mobile shell to GitHub on every
**Ship It!** click. From there [Bifrost](https://bifrost.nativephp.com)
takes the projected branch and produces signed binaries.

> **Status — pre-release, feedback welcome.** Not yet production-ready;
> APIs and schemas may change before 1.0. Mobile only (`mobile_ios`,
> `mobile_android`); desktop targets are reserved in the schema but
> rejected at the manifest layer.

## What you get

- **Block-editor authoring.** Build screens with the same Gutenberg
  surface you use everywhere else — plus 30+ purpose-built blocks for
  articles, queries, navigation, native actions, and search.
- **Single source of truth.** App branding, navigation, deep links,
  store identity, and capabilities all live on the App post; screens
  are children. Edit once, projected everywhere.
- **Standalone projection.** No control plane, no Hub, no shared
  database. The publisher projects directly to your GitHub repo via
  the Git Data API; Bifrost picks up from there.
- **Signed manifests.** Every projected build is HMAC-signed. Shells
  refuse to render tampered manifests.
- **Live preview.** A device-frame dashboard renders the manifest in
  context with a build-readiness checklist. Gutenberg's Preview button
  is rewired to it.
- **Extension-friendly.** A documented filter surface lets third-party
  plugins contribute blocks, deep-link resolvers, manifest shaping,
  and per-app capability advertisements without forking core.

## Install

Publishers — download the latest release ZIP and upload it via
**Plugins → Add New → Upload Plugin**. The release ZIP ships with
`vendor/` and `dist/` already populated; nothing further is needed on
the production host.

Developers working from source:

```sh
cd wp-content/plugins
git clone https://github.com/webmultipliers/mustuse-apps-pub.git
cd mustuse-apps-pub
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

Activation registers the two custom post types (`mua_app`,
`mua_app_screen`) and flushes rewrite rules.

## Quick start

1. **Configure publisher defaults.** WP Admin → MustUse Apps →
   Settings. Set publisher name, brand colours, support email.
2. **Create an app.** WP Admin → MustUse Apps → Add New — or hit
   **Create demo app** on the empty-apps screen for a one-click
   kitchen sink that wires every block-classified native capability
   onto a single screen. Pick a slug carefully — it bakes into the
   bundle id `com.mustuse.{slug}` and locks once you ship.
3. **Add screens.** In the **Screens & Navigation** metabox click
   **Add Screen**. Each screen is a normal Gutenberg post, drag-nest
   to build the tree, mark one as **Use as home**.
4. **Configure GitHub + Bifrost.** Drop a repo URL + PAT into the
   Distribution metabox; create the matching Bifrost project.
5. **Ship it.** The plugin assembles the shell, signs the manifest,
   and pushes to your repo. Trigger a Bifrost build against the new
   branch when you're ready.

## Requirements

|           |                  |
| --------- | ---------------- |
| WordPress | 6.4+             |
| PHP       | 8.2+             |
| Composer  | 2.x (build-time) |
| Node      | 20+ (build-time) |

## Documentation

Deeper docs live in [`docs/`](docs/README.md):

- [Architecture](docs/architecture.md) — how the pieces fit together
- [Authoring](docs/authoring.md) — using the editor end-to-end
- [Blocks](docs/blocks.md) — block library and the atomic block pattern
- [Build and Ship](docs/build-and-ship.md) — what happens after Ship It
- [REST API](docs/rest-api.md) — every endpoint, who consumes it
- [Extensions](docs/extensions.md) — filter surface for third-party plugins
- [Development](docs/development.md) — local setup, tests, releases

## Companion repository

[`mustuse-apps`](https://github.com/webmultipliers/mustuse-apps) holds
the cross-system architecture, ADRs, and shared glossary.

## Licence

GPL-2.0-or-later — see [`LICENSE`](LICENSE). Same license as WordPress
itself; required because the plugin bundles [Blockstudio](https://github.com/inline0/blockstudio)
(GPL-2.0-or-later) as a runtime dependency.

## Contributing

See the [contributing notes](docs/development.md#contributing). PRs go
to `development`; CI must be green and the version header bumped before
merging to `main`.
