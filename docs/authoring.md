# Authoring

How a publisher actually builds an app inside WordPress: the App edit
screen, the screen tree, slug rules, the home flag, deep links, and the
validation surface that gates Ship It.

## Start with a demo app

Two scaffolds on the empty-apps screen + in Settings:

- **Kitchen-sink** — every native capability the plugin surfaces
  (camera, geolocation, biometrics, haptics, dialogs, push, etc.) on
  a single screen. Useful for verifying device parity and exploring
  the native-action block palette.
- **Content showcase** — nine-screen WordPress editorial site: home
  feed, post/page detail, category + tag archives, author profile,
  search, saved, about, and a template-cascade 404. Uses only
  content-driven blocks; ideal for publishers mirroring an existing
  site into an app.

Both seed a stubbed capability advertisement so every block renders
without a connected shell. You land directly in the screen editor.

## The App edit screen

The App canvas is a standard Gutenberg surface flanked by metaboxes.
The canvas itself is read-only preview content (mobile-preview,
navigation-preview, manifest-preview, capability-status, branding-preview)
— all real configuration lives in the metaboxes.

| Metabox               | Position     | What it holds                                                                  |
| --------------------- | ------------ | ------------------------------------------------------------------------------ |
| App Identity          | side, high   | Slug (locked after first ship), iOS/Android checkboxes, version (SemVer), Open preview |
| Ship Readiness        | normal, high | Live ManifestValidator + BlockSchemaAuditor results                            |
| Screens & Navigation  | normal, high | Drag-reorder / drag-nest tree with inline add / edit / preview / delete        |
| Branding              | normal       | Primary / accent / background colors, icon, splash, logo                       |
| Distribution & Builds | normal       | GitHub repo URL + PAT, Test Connection, Ship It!, deployment map, ship history |
| Shell API Key         | side         | Key prefix display, "generate / rotate on save" checkbox                       |
| Native Store Identity | normal       | Bundle ID, Apple Team ID, Android Package, SHA-256 fingerprints                |
| Deep Linking          | side         | URL scheme + verified host                                                     |
| Owners                | side, low    | Per-app access list — admin-only field; non-admins see read-only summary       |
| Bifrost Setup         | normal, low  | Read-only env-var reference (values for non-secrets, 🔒 for secrets)            |
| Extensions            | normal, low  | Per-app toggle for registered third-party plugins                              |

## Slug rules

App slugs and screen slugs are part of the contract with shipped
binaries. The plugin enforces two locks:

- **App slug** — once the app has shipped (`_mua_last_build_at` set or
  `_mua_app_version_code > 1`), the slug field becomes disabled and any
  attempt to rename via REST or Quick Edit is reverted by a
  `wp_insert_post_data` filter. The bundle id `com.mustuse.{slug}` and
  manifest URL `/apps/{slug}/manifest` would otherwise change, orphaning
  every install.
- **Screen slugs** — scoped per-app via
  [`Screen::normaliseSlugWithinApp`](../src/Data/Models/Screen.php).
  Two apps can each have a screen slugged `home`; WordPress's global
  uniquifier is overridden post-save when no sibling in the same app
  owns the intended slug. Once the app has shipped, the screen's slug
  is pinned to `_mua_locked_slug` meta and reverted on rename.

When a rename is reverted, an admin notice surfaces on the next page
load via a one-shot transient.

## Adding screens

The **Screens & Navigation** metabox renders a nested `<ol>` of every
screen for the app, hydrated by [SortableJS](https://sortablejs.github.io/Sortable/).

- Click **Add Screen** to create a top-level screen.
- Click **+ Child** on any row to nest a new screen under it.
- Drag the handle to reorder among siblings; drag onto another's list
  to nest. Each drop POSTs the flat payload `{id, parent, order}[]` to
  `POST /apps/{id}/screens/tree` — no Save button, so closing a tab
  mid-edit never loses the arrangement.
- Tree position is persisted as WP-native `post_parent` + `menu_order`.

Delete is recursive with a confirmation prompt when the screen has
children.

## The Screen edit page

Screens get four metaboxes:

- **App** — back-link to the owning app.
- **Screen Slug** — manifest id, lowercase letters/digits/hyphens only.
- **Deep Link Path** — template like `/article/{slug}`.
- **Content Source & Navigation** — role picker plus per-role fields:
  - **Static** (default) — renders its blocks only.
  - **Archive** — exposes Standard (post-type + taxonomy + term) or
    Custom (registered PHP provider) source pickers.
  - **Detail** — exposes an inline URL-pattern input. Ship It blocks
    if the pattern is empty.
  - **Show in bottom nav** + nav order (capped at 5 across all
    screens per iOS HIG).
  - **Show in side nav** + side-nav order. Side-nav is a
    publisher-curated flat list with no cap. When empty across the
    app, shells fall back to the auto-generated drawer (every
    published screen, hierarchical).
  - **Use as home** — checkbox. Only one screen per app can be home;
    setting it clears the flag on siblings automatically.

## Home election

The shell routes `/` to the home screen. Election order:

1. Explicit `_mua_is_home` flag.
2. First show-in-nav screen by `nav_order`.
3. First published screen.

The Ship Readiness metabox surfaces a warning when no explicit home is
flagged, since deterministic routing depends on it.

## Deep linking (per-app)

Set `deeplink_scheme` (e.g. `myapp`) and `deeplink_host` (a verified
HTTPS domain) in the Deep Linking metabox. The plugin serves the
verification files at the **domain root**:

```
/.well-known/apple-app-site-association
/.well-known/assetlinks.json
```

Both are dynamic. They aggregate every published `mua_app` whose
`deeplink_host` matches the current site host, emitting:

- AASA `details[]` from `bundle_id` (+ optional `apple_team_id` prefix).
- `assetlinks.json` `statements[]` from `android_package_name` and the
  configured `android_sha256_fingerprints[]`.

Inbound deep-link resolution runs through `POST /apps/{slug}/resolve-deeplink`
and walks three layers (see [REST API](rest-api.md) for the full
contract):

1. Screen-authored templates from `_mua_deeplink_path`. Templates pass
   through a strict allowlist guard
   ([`isSafeTemplate`](../src/Content/DeeplinkResolver.php)) that
   rejects anchors, alternations, quantifiers, and encoded-slash
   variants to prevent regex injection / ReDoS.
2. Built-in URL shapes: `/article|post/{id|slug}`, `/page/{id|slug}`,
   `/category/{slug}`, `/tag/{slug}`, `/author/{slug|id}`, `/search?q=...`.
3. Extension filter `mua_deeplink_resolve` for custom schemes.

All matches return the same context shape used by `/content` items and
QueryLoop iterations.

## Preview

Click **Open preview ↗** on the App Identity metabox (or the **Preview**
link on any screen row). Opens a device-frame dashboard in a new tab:

- Left column — identity (icon, bundle id, version) + capability usage.
- Centre — device frame with simulated NativePHP safe-area zones, the
  rendered screen content, and bottom-tab nav.
- Right — manifest summary + build readiness checklist
  (`DeploymentRequirements`).

Gutenberg's native Preview button is rewritten to this URL via the
`preview_post_link` filter. Autosaves are honoured (title, content,
excerpt) so live editing + preview-in-another-tab gives near-immediate
feedback.

`native-action` blocks show a hover tooltip
(*"Camera capability — runs natively on device"*) rather than firing
capabilities; real behaviour needs a shell on a device.

## Per-app permissions

The **Owners** sidebar metabox (admin-only) accepts a list of usernames
or user IDs (one per line). When populated, only listed users plus
site admins (`manage_options`) can edit, delete, or ship the app. When
empty, the existing back-compat behaviour applies — anyone with the
underlying primitive cap can act.

Permissions cascade: a user who can't edit the App also can't edit any
of its child Screens. See
[architecture.md → Per-app permissions](architecture.md#per-app-permissions)
for the cap-mapping mechanics.

## Ship Readiness

The Ship Readiness metabox runs two validators every time the App edit
screen loads:

- [`ManifestValidator`](../src/Manifest/ManifestValidator.php) — schema
  shape, navigation caps, screen types, capability gates, callback URLs.
- [`BlockSchemaAuditor`](../src/Blocks/BlockSchemaAuditor.php) — detects
  drift between every block's `block.json` schema and its
  `mobile.blade.php` renderer.

Findings render as grouped error lists; a clean state shows a green
"Ready to ship" banner. The metabox also surfaces screen / nav counts
and warns when no home is flagged.
