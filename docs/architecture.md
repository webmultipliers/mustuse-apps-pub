# Architecture

How the Publisher plugin fits in the wider MustUse Apps system, what it
owns, and how a single Ship It click turns WordPress content into a
manifest a mobile shell can render.

## Where it fits

```
+----------------+      GitHub       +---------+
| WordPress site |  --------------->  | Bifrost |
| + this plugin  |   publisher push   | (build) |
+----------------+                    +---------+
        |                                  |
        | signed JSON manifest, content    |
        v                                  v
   +---------+                        +--------+
   | Shells  |  <-- manifest-driven   | Stores |
   +---------+                        +--------+
```

The publisher is a **standalone projection tool**. Its responsibility
ends once it has projected an assembled shell repository to GitHub. From
there the publisher manually triggers a Bifrost build that produces
signed iOS / Android binaries.

There is no Hub, no control plane, no shared database. One install =
one publisher; that publisher has many apps.

## Custom post types

| Slug             | Purpose                                 | Linkage                                  |
| ---------------- | --------------------------------------- | ---------------------------------------- |
| `mua_app`        | One row per mobile app                  | root                                     |
| `mua_app_screen` | One screen authored in the block editor | `_mua_app_id` meta, `post_parent` (tree) |

Both are `public => false` and `publicly_queryable => false` — the
shells never hit the public WordPress URL for a screen, and the front
end has no template for these post types. Preview is served by a
dedicated [`PreviewRoute`](../src/Preview/PreviewRoute.php) at
`?mua_preview={post_id}&preview_nonce={nonce}` so Gutenberg's Preview
button still works.

The App edit screen is where everything happens; Screen posts are
accessed from the App's screen tree (or deep-linked from there).

## Where data lives

- **App configuration** — branding, version, navigation, deep-link
  scheme/host, native store identity, GitHub repo, API key hash,
  extensions — lives in `post_meta` on the `mua_app` post, prefixed
  `_mua_*`.
- **Screen content** lives in `post_content` as serialised Gutenberg
  blocks drawn from `mustuse-apps-pub/*` plus a small core allowlist
  (see [blocks.md](blocks.md)).
- **Screen tree position** uses WP-native `post_parent` and `menu_order`.
  The Screen post type is hierarchical; no custom pivot table.
- **Publisher-level defaults** (brand DNA, manifest signing key) live
  in `wp_options`.
- **Slug locks** — once an app has shipped, the post_name on `mua_app`
  is held steady by a `wp_insert_post_data` filter and screen post_names
  are pinned via `_mua_locked_slug` meta. Both surfaces a one-shot admin
  notice when a rename attempt is reverted.
- **Shell access logs** (when `WP_DEBUG_LOG` is on) flow through
  [`ShellAccessLog`](../src/Logging/ShellAccessLog.php), which redacts
  credentials, signatures, secrets, tokens, and API keys before emitting.

## Manifest pipeline

```
GET /wp-json/mustuse-apps-pub/v1/apps/{slug}/manifest
  → AppKeyAuth (bearer)
  → ManifestRoute → cache hit? return signed
                  → ManifestBuilder (app meta + screens + tree + filters)
                  → ManifestValidator (schema, capability gates, callback URLs)
                  → ManifestSigner (HMAC-SHA256)
                  → cache 1h
                  → response with X-MUA-Signature
```

The manifest carries:

- `version` + `min_shell_version` — schema version of this manifest
  and the minimum shell schema version required to render it. See
  [Schema versioning](#schema-versioning) below.
- `screens[*]` — flat list with stable `id`, `path`, `title`,
  `block_tree`, optional `is_home` flag.
- `navigation` — `{bottom_nav: [...], side_nav: [...], drawer: [...]}`.
  Bottom-nav is the tab-bar (capped at five per iOS HIG). Side-nav is
  a publisher-curated flat list (no cap). Drawer is the full screen
  tree projected hierarchically — shells fall back to it when
  side-nav is empty.
- `screen_tree` — the same hierarchy at top level for shells that
  prefer to walk it directly.
- `routing` — archive query bindings + detail-screen URL templates.
- `endpoints` — every URL the shell will need.
- `requiredCapabilities`, `cache_policy`, `cache_invalidations`,
  `branding`, `assets`, `extensions`, `subscriber_state`.

Path rules are fixed in
[`ScreenRouteProjector`](../src/Routing/ScreenRouteProjector.php):

- the home screen → `/`
- detail screens with a `_mua_deeplink_path` → that template (e.g.
  `/article/{slug}`)
- everything else → `/{slug}`

Home election: explicit `_mua_is_home` flag wins; otherwise the first
show-in-nav screen by `nav_order`; otherwise the first published screen.

## Runtime content — not baked into the manifest

Manifests carry **intent**, not content. An Article Grid block ships its
query attributes (postType, count, category…) but the shell fetches the
result live from `/content` when the block mounts. A Post Title block
ships display attributes; the shell feeds it the current route's post
via `$context.post.title`.

The shell's `DynamicBlock` Livewire component reads each block's
projected `native.json`, resolves `dataSource` attribute templates, and
calls `PubClient::query()` which runs a stale-while-revalidate cache
against the pub's `/content` or `/terms` endpoints. Pull-to-refresh
broadcasts a `block-refresh` event that invalidates every dynamic block
on the current screen at once.

Context flows through
[`DeeplinkResolver`](../src/Content/DeeplinkResolver.php) (pub-side)
and [`ContextBuilder`](../src/Content/ContextBuilder.php), the single
serialiser for post / term / author shapes. The same shape powers
`/content` responses, `/resolve-deeplink` payloads, and QueryLoop
iterations, so a `post-title` block reads `context.post.title`
regardless of whether it landed there from an article URL, a loop
iteration, or a search-results page.

## Async work

All async / scheduled work runs through Action Scheduler under the
`mustuse-apps-pub` group. No `wp_schedule_event`, no inline outbound
HTTP from request handlers.

| Job                         | Hook                            | Trigger                |
| --------------------------- | ------------------------------- | ---------------------- |
| Manifest cache invalidation | `mua_invalidate_manifest_cache` | post saves & deletions |
| Manifest rebuild            | `mua_rebuild_manifest`          | scheduled refresh      |
| GitHub projection           | `mua_project_build_to_github`   | enqueued by ShipRoute  |

## Schema versioning

[`ManifestBuilder`](../src/Manifest/ManifestBuilder.php) carries two
constants the manifest emits on every build:

- `SCHEMA_VERSION` — the version of this manifest. Bumped whenever the
  publisher emits any change in the manifest shape, breaking or not.
- `MIN_SHELL_SCHEMA_VERSION` — the minimum schema version a shell
  must understand to render this manifest correctly. Stays at the
  oldest version every required field has been present since.

Shells declare a matching `SUPPORTED_SCHEMA_VERSION` constant
(see [`NativeEdge`](../assets/blueprints/mobile-shell/app/Livewire/NativeEdge.php))
and refuse to render a manifest whose `min_shell_version` exceeds
their own. This lets us add optional fields without touching shipped
shells, and forces a shell rebuild when a breaking change lands.

The validator rejects manifests with non-positive `version`, with
`version` higher than the publisher's known max, or with
`min_shell_version > version`.

## Per-app permissions

Each App can carry an explicit list of WordPress user IDs in
`_mua_app_owners`. When set, only those users (and site admins via
`manage_options`) may edit, delete, or ship the App and its child
Screens. When empty, the existing back-compat behaviour applies —
anyone with the underlying primitive cap can act.

Wired by [`AppPermissions`](../src/Admin/AppPermissions.php) via a
`map_meta_cap` filter. Gated caps: `edit_post`, `delete_post`,
`publish_post`, `read_post`, `mua_ship_app`. Screen-post caps resolve
through `_mua_app_id` to the owning app's owner list.

## Lifecycle hooks

[`PostLifecycle`](../src/Events/PostLifecycle.php) hooks four WP events:

- `save_post` — schedule cache invalidation for the affected app(s).
- `wp_trash_post` — cascade-trash every screen owned by the trashed
  app so the editor never shows orphan rows.
- `untrashed_post` — cascade-untrash matching screens.
- `before_delete_post` — cascade hard-delete screens (any status,
  including trash).
- `updated_option` (for `mua_publisher_branding`) — invalidate every
  app's manifest cache because branding cascades.
