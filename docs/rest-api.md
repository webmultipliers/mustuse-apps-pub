# REST API

Every endpoint the publisher exposes, who consumes it, and what the
auth / cache / signature contracts look like.

Everything publisher-facing is namespaced under
`/wp-json/mustuse-apps-pub/v1`. Well-known files are served at the
domain root instead.

## Auth

| Audience    | Auth                                | Set on                                        |
| ----------- | ----------------------------------- | --------------------------------------------- |
| Mobile shell | `Authorization: Bearer {MUA_APPKEY}` | every Shell route via `AppKeyAuth::verify()`  |
| Editorial UI | WP capability checks               | every Editorial route                         |
| Public well-known | none                          | AASA + assetlinks                             |

`MUA_APPKEY` is the per-publisher manifest signing key. The shell
authenticates as the app it claims to be the projected build for; an
attacker would need both the key and a matching slug.

## Shell API (per-app bearer token — AppKeyAuth)

| Method       | Path                            | Purpose                                                                                                                                                          |
| ------------ | ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET          | `/apps/{slug}/manifest`         | signed manifest with `X-MUA-Signature` header                                                                                                                    |
| GET          | `/apps/{slug}/content`          | posts — list + single; `id`, `post_type` (incl. `any`/csv), `page`, `per_page`, `search`, `category`, `taxonomy`+`term`, `author`, `orderby`, `order`, `exclude` |
| GET          | `/apps/{slug}/terms`            | taxonomy terms — `taxonomy`, `include`, `per_page`, `orderby`, `order`                                                                                           |
| POST         | `/apps/{slug}/capabilities`     | shell capability advertisement                                                                                                                                   |
| POST         | `/apps/{slug}/resolve-deeplink` | path → `{screen_id, context}`                                                                                                                                    |
| POST         | `/apps/{slug}/auth/complete`    | publisher auth callback                                                                                                                                          |
| GET          | `/apps/{slug}/auth/status`      | subscriber state for current device                                                                                                                              |
| POST         | `/apps/{slug}/auth/logout`      | sign out                                                                                                                                                         |
| GET          | `/apps/{slug}/subscriber/state` | refresh subscriber state                                                                                                                                         |
| GET/POST/DEL | `/apps/{slug}/bookmarks`        | per-device bookmarks (`X-MUA-Device-Id`)                                                                                                                         |
| GET/POST     | `/apps/{slug}/history`          | per-device read history                                                                                                                                          |
| POST         | `/apps/{slug}/push/enroll`      | register push token for device                                                                                                                                   |

### Caching headers

`/content` and `/terms` responses carry `X-MUA-Content-Revision` — a
monotonic token shells compare against their cached value to skip work
when nothing has changed. Server-side responses are also `wp_cache`'d
briefly to absorb traffic spikes (default 60s for content, 1800s for
terms; both filterable).

### Manifest cache

Manifest responses are transient-cached for one hour, keyed by app id.
Invalidated by `PostLifecycle` whenever an app or one of its screens
saves, or when `mua_publisher_branding` updates. The transient key is
internal — the cache is per-publisher, not per-shell.

## Editorial API (capability-gated)

| Method | Path                                 | Purpose                                 |
| ------ | ------------------------------------ | --------------------------------------- |
| POST   | `/apps/{id}/ship`                    | dispatch a build to GitHub              |
| GET    | `/apps/{id}/build-status`            | poll the most recent build              |
| POST   | `/apps/{id}/screens`                 | create a screen (optional `parent_id`)  |
| POST   | `/apps/{id}/screens/tree`            | bulk reparent + reorder                 |
| DELETE | `/apps/{id}/screens/{screen_id}`     | recursive delete                        |
| GET    | `/apps/{id}/deployment-requirements` | readiness checks for the deployment map |
| POST   | `/test-github-connection`            | validate a GitHub token + repo URL      |

The `mua_ship_app` capability gates Ship It; `manage_options` is
treated as a superset.

## Public (well-known, served at domain root)

| Method | Path                                      |
| ------ | ----------------------------------------- |
| GET    | `/.well-known/apple-app-site-association` |
| GET    | `/.well-known/assetlinks.json`            |

Both files aggregate every published `mua_app` whose `deeplink_host`
matches the current site host. Missing media on an app means that app
is silently excluded from the file — never an error.

## Preview

| Method | Path                                            | Purpose                                |
| ------ | ----------------------------------------------- | -------------------------------------- |
| GET    | `/?mua_preview={post_id}&preview_nonce={nonce}` | device-frame dashboard (front-end URL) |

Routed by `template_redirect` rather than a REST endpoint so it can
render a no-chrome dashboard with admin-bar + Vite assets and bypass
the theme template hierarchy. Auth is WP's standard preview-nonce
convention.

## Conventions

- Every endpoint that returns post / term / author shapes uses
  [`ContextBuilder`](../src/Content/ContextBuilder.php). `/content`
  items, `/resolve-deeplink` payloads, and QueryLoop iterations all
  share one schema so context-bound blocks (`post-title`, etc.) read
  the same fields regardless of how the route was reached.
- Sanitisation lives entirely in this repo — no shared SDK.
- All async work is enqueued via Action Scheduler under the
  `mustuse-apps-pub` group. No inline outbound HTTP from request
  handlers.
- Shell access logs flow through
  [`ShellAccessLog`](../src/Logging/ShellAccessLog.php), which redacts
  credentials, signatures, secrets, tokens, and API keys before
  emitting to `error_log`.
