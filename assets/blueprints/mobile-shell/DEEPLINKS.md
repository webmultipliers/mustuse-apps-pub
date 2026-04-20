# Deeplinks — OS intents → app routes

The shell's routes/web.php catches everything with `GET /{any?}`, so **any
path the NativePHP deeplink layer forwards to the shell's internal HTTP
server will already reach `NativeEdge`**. From there `NativeEdge::mount`
either exact-matches a manifest screen or POSTs to the pub's
`/resolve-deeplink` endpoint — you don't need to add another routing layer.

What you **do** need is to tell the OS that your URL scheme should open
the app.

## Configuring the URL scheme

Both Android (intent filters) and iOS (associated domains / URL types) are
driven by two env vars in the projected build's `.env`:

| Var | Example | Purpose |
|---|---|---|
| `NATIVEPHP_DEEPLINK_SCHEME` | `mustuse` | Custom URL scheme — e.g. `mustuse://article/foo` |
| `NATIVEPHP_DEEPLINK_HOST`   | `mustuse.com` | Universal / App Links host — `https://mustuse.com/article/foo` |

Both are optional and can be set independently.

`BuildAssembler::envForBifrost()` already emits these rows from the app's
`_mua_deeplink_scheme` / `_mua_deeplink_host` metas, so once an author
sets them in the editor they land in the projected `.env` and the native
build pipeline (iOS `Info.plist`, Android `AndroidManifest.xml`) picks
them up automatically.

## What the shell does with an incoming intent

When the OS hands off a URL like `mustuse://article/my-slug` or
`https://mustuse.com/article/my-slug`, NativePHP's runtime routes the
in-app path portion (`/article/my-slug`) to the internal HTTP server.
Laravel matches `GET /{any?}` → `NativeEdge`. Then:

1. `mount` normalizes the path + preserves any query string.
2. `loadManifest` tries an exact screen match by `path`.
3. If no match, it POSTs to the pub's `/resolve-deeplink` with the full
   path+query. The resolver walks:
   - Publisher-defined `_mua_deeplink_path` templates (e.g.
     `/article/{slug}` → article-detail screen).
   - Built-in content patterns (`/article/{id|slug}`, `/page/{…}`,
     `/category/{slug}`, `/tag/{slug}`, `/author/{slug|id}`, `/search`).
   - The `mua_deeplink_resolve` filter for custom handlers.
4. A resolved `{screen_id, context}` response is loaded in-app without
   another network round-trip, and context-bound blocks (`post-title`,
   `post-featured-image`, ...) render their fields from `$context`.

## Testing locally

Before shipping, exercise each resolver path from the WP admin:

```
# Exact manifest screen
GET /
# Pattern match
GET /article/my-slug
# Tax archive
GET /category/news
# Search
GET /search?q=hello
```

Each should return `{screen_id, context}` from
`POST /wp-json/mustuse-apps-pub/v1/apps/{slug}/resolve-deeplink` with a
valid `MUA_APPKEY` bearer token.
