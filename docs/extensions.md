# Extensions

The publisher exposes a stable filter surface so third-party plugins
can extend the manifest, content pipeline, block library, deep-link
resolution, and admin canvas without forking the core.

## Filter reference

| Filter                                                               | Used for                                        |
| -------------------------------------------------------------------- | ----------------------------------------------- |
| `mua_app_manifest`                                                   | top-level manifest mutation                     |
| `mua_app_endpoints`                                                  | contribute REST URLs                            |
| `mua_app_navigation`                                                 | contribute / rewrite navigation                 |
| `mua_app_screen_tree`                                                | contribute / rewrite the screen hierarchy       |
| `mua_screen_block_tree`                                              | per-screen block tree mutation                  |
| `mua_block_attributes`                                               | per-block attribute shaping                     |
| `mua_renderer_descriptions`                                          | register shell renderers (built-in + extension) |
| `mua_canvas_blocks`                                                  | contribute admin canvas blocks                  |
| `mua_block_sources`                                                  | contribute block source folders to project      |
| `mua_allowed_blocks`                                                 | extend the screen-editor allowlist              |
| `mua_app_assets`                                                     | override icon / logo / splash                   |
| `mua_app_cache_policy`                                               | override the three-tier cache TTLs              |
| `mua_app_cache_invalidations`                                        | revision-token contribution                     |
| `mua_app_subscriber_state`                                           | paywall subscriber-state contribution           |
| `mua_auth_get_status` / `mua_auth_resolve_token` / `mua_auth_logout` | auth integration                                |
| `mua_content_query` / `mua_content_post_types` / `mua_content_item`  | content endpoint shaping                        |
| `mua_deeplink_resolve`                                               | custom deep-link resolution                     |
| `mua_app_requirements_check`                                         | contribute deployment-readiness checks          |

## Activating an extension

Extensions are registered globally (the publisher discovers them via
WP's plugin loader) and toggled on a per-app basis from the App edit
screen's **Extensions** metabox. Toggle state lives in `_mua_extensions`
post meta and is read by [`ExtensionRegistry::getActiveForApp`](../src/Extensions/ExtensionRegistry.php).

The `mua_allowed_blocks` filter fires inside `ManifestBuilder::build`
when an app has any active extension, so block-allowlist contributions
are scoped to apps that opted in.

## Contributing blocks from a third-party plugin

```php
add_filter( 'mua_block_sources', function ( array $sources ): array {
    $sources['my-block'] = __DIR__ . '/blocks/my-block';
    return $sources;
} );
```

Each contributed folder must follow the atomic block pattern
(`block.json` + `index.php` + `native.json` + `mobile.blade.php`,
optional `styles.inline.scss`). See [blocks.md](blocks.md) for the
contract.

## Custom deep-link handlers

```php
add_filter( 'mua_deeplink_resolve', function ( ?array $resolved, string $path, App $app ): ?array {
    if ( ! str_starts_with( $path, '/promo/' ) ) {
        return $resolved;
    }
    return [
        'screen_id' => 'promo-detail',
        'context'   => [ 'promo' => $this->lookupPromo( $path ) ],
    ];
}, 10, 3 );
```

The custom resolver runs after screen-authored templates and the
built-in URL shapes, so it's a fall-through hook. Returning `null`
hands off to the next layer.

## Capability advertisement

Extensions cannot add capabilities to the registry — the v1 surface is
fixed (see [blocks.md](blocks.md#capabilities-vocabulary)). They can,
however, use existing capabilities to gate their own blocks via
`requiredCapabilities` in `native.json`. Blocks whose capability is
not advertised by a connected shell are stripped from the projected
block tree before the manifest is signed.

## Push notifications and monetisation

Both will land as extensions rather than core features. Push enrolment
already has a route (`POST /apps/{slug}/push/enroll`) and a block
(`push-enroll`); the actual delivery integration is intentionally
deferred so extensions can pick the provider (APNs direct vs.
Firebase, OneSignal, etc.) without a core opinion.
