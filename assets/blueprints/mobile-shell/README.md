# MustUse Apps — Mobile Shell Blueprint

Laravel 12 + Livewire 3 + NativePHP for Mobile v3 base that every
MustUse Apps build projects on top of. The Publisher plugin's
`BuildAssembler` copies this tree into a per-app build directory, drops
the signed manifest + each block's projected artefacts alongside it,
and ships the result to a GitHub repository. Bifrost turns that
repository into iOS / Android binaries.

**Read this if you are** editing the shell's routing, rendering, or
persistence layers. Per-block authoring lives in the pub's `src/Blocks/`
directory (not here).

---

## Runtime story

```
OS intent  ─►  Laravel route  /{any?}  ─►  App\Livewire\NativeEdge
                                                │
                          ┌─────────────────────┼─────────────────────┐
                          ▼                     ▼                     ▼
                   exact-match                resolve-deeplink   context-bound
                   screen path                on the pub          fields (post-title,
                                                                   post-featured-image…)
```

1. **Boot.** `NativeEdge::mount()` captures `$any` plus the query
   string, loads `storage/app/mua-manifest.json`, verifies the HMAC
   signature against `MUA_APPKEY`, and tries to exact-match a screen
   by `path`.
2. **Pattern resolution.** If nothing matched, POST the full path to
   `/resolve-deeplink`. The pub returns `{screen_id, context}`; the
   shell swaps in that screen and surfaces `$context` for the
   rendered block tree.
3. **Visit recording.** When the resolved context has a `post.id`,
   `Persistence::recordVisit()` logs it to `/history`.
4. **Block dispatch.** Every block in the screen's `block_tree` goes
   through `resources/views/livewire/partials/dispatch-block.blade.php`,
   which reads the block's projected `native.json` and routes to one
   of four renderers.

### Dispatch paths

| Block type                   | Signal                        | Renderer                              |
|------------------------------|-------------------------------|---------------------------------------|
| `query-loop`                 | slug check                    | `<livewire:query-loop>` (one per loop)|
| `post-template` (orphan)     | slug check                    | inline children render                |
| any block with `dataSource`  | `native.json.dataSource`      | `<livewire:dynamic-block>` (SWR)      |
| anything else                | default                       | plain Blade with `$context` prop      |

---

## Manifest location

Bifrost's mobile runtime doesn't reliably extract `storage/app/` on
first launch. `BuildAssembler` writes the manifest + signature to
**two** places:

- `storage/app/mua-manifest.json` (primary, writable)
- `mua-manifest.json` at the repo root (bundle-safe fallback)

`NativeEdge::resolveManifestPaths()` reads from storage first, falls
back to base path, and self-heals the storage copy on first successful
read.

---

## Core Livewire components

| Component       | File                                       | Role                                               |
|-----------------|--------------------------------------------|----------------------------------------------------|
| `NativeEdge`    | `app/Livewire/NativeEdge.php`              | Full-page renderer — one per request               |
| `DynamicBlock`  | `app/Livewire/DynamicBlock.php`            | Wraps any block with a `dataSource` in SWR fetch   |
| `QueryLoop`     | `app/Livewire/QueryLoop.php`               | Query container — iterates `post-template`         |
| `SearchBox`     | `app/Livewire/SearchBox.php`               | Search input → navigates to `/search?q=…`          |
| `BookmarkToggle`| `app/Livewire/BookmarkToggle.php`          | Per-post bookmark mutator                          |
| `PushEnroll`    | `app/Livewire/PushEnroll.php`              | OS push permission prompt + token sync             |

Every dynamic / query block uses **one** Livewire instance per block
— never one per loop item. `QueryLoop` iterates its
`post-template`'s inner blocks as plain Blade with `$context.post`
set per iteration, so a 10-item grid with five context blocks per
card is still 1 Livewire component, not 50.

---

## Key services

- **`app/Support/PubClient.php`** — HTTP client for the pub REST API.
  Reads endpoint URLs from the manifest's `endpoints` map, authenticates
  with `Bearer ${MUA_APPKEY}`, and runs a stale-while-revalidate cache:
  fresh returns cached, stale returns cached + deferred refresh, miss
  fetches synchronously, network failure returns the last cached body.
- **`app/Services/Persistence.php`** — device-scoped personalization.
  Generates a per-install device id with `random_bytes(32)` on first
  launch, persists it via `SecureStorage`, and mirrors
  bookmarks / history / push-token mutations to the pub keyed by the
  `X-MUA-Device-Id` header.
- **`app/Support/BlockAssetCollector.php`** — walks the rendered
  block tree and emits inline `<style>` / `<script>` tags for every
  block slug present. Per-block CSS comes from Blockstudio's compiled
  `_dist/styles.inline-*.css` that `BuildAssembler::projectBlockAssets`
  mirrors into `public/blocks/{slug}/`.

---

## Routes

```
GET /{any?}  →  App\Livewire\NativeEdge  (route name: native-edge)
```

That's it. Everything below that is decided by `NativeEdge::mount()`
plus the pub's `/resolve-deeplink` endpoint. See
[`DEEPLINKS.md`](DEEPLINKS.md) for OS-intent configuration and
testing.

---

## Layout & assets

Single Livewire page layout at
`resources/views/components/layouts/app.blade.php` (Livewire 3 resolves
`components.layouts.app` for full-page components). The layout
inlines three stacked style layers:

1. **Shell base** — tokens + body reset + screen frame from
   `resources/css/app.css`. ~60 lines, no block-specific rules.
2. **Branding variables** — `--mua-color-primary` /
   `--mua-color-accent` / `--mua-color-background` read from the
   manifest so block SCSS inherits the publisher's palette.
3. **Per-block CSS** — only the blocks actually present on this
   screen, inlined via `BlockAssetCollector`.

No global `shell.css` with every block's rules baked in. A screen
with just hero + article-list inlines hero.css + article-list.css and
nothing else.

---

## Environment

Variables Bifrost injects per app at build time:

| Name                            | Purpose                                         |
|---------------------------------|-------------------------------------------------|
| `MUA_APPKEY`                    | Bearer token for every pub REST call            |
| `MUA_MANIFEST_URL`              | Fallback if the manifest is missing on disk     |
| `MUA_PUBLISHER_URL`             | Pub origin (display / diagnostics only)         |
| `NATIVEPHP_APP_ID`              | `com.mustuse.{slug}`                             |
| `NATIVEPHP_APP_VERSION`         | SemVer                                          |
| `NATIVEPHP_APP_VERSION_CODE`    | Play Store build number                         |
| `NATIVEPHP_DEEPLINK_SCHEME`     | Custom URL scheme (e.g. `mustuse`)              |
| `NATIVEPHP_DEEPLINK_HOST`       | Universal / App Links host                      |
| `NATIVEPHP_START_URL`           | Initial path (default `/`)                      |

`BuildAssembler::envForBifrost()` generates the full row list; non-
secret values are embedded in `.env.example`, secrets (`MUA_APPKEY`)
are left blank for Bifrost's env UI.

---

## What not to edit here

Everything block-authored lives in the pub at `src/Blocks/{slug}/`.
Each block's `mobile.blade.php` is **projected** into this shell at
Ship-It time under `resources/views/components/mustuse/{slug}.blade.php`
— edits made directly in the projected repo are overwritten on every
ship.

Config files (`bootstrap/app.php`, `config/*.php`), Laravel /
Livewire / NativePHP conventions, and the app/Providers bootstrap
are intentionally thin. Keep them that way — customization belongs
on the pub side via filters, not by forking the shell.
