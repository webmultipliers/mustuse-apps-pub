# Blocks

The block library is the publisher's UI vocabulary — what authors can
drop on a screen and what the shell knows how to render. Every block
lives in `src/Blocks/{slug}/` and follows the atomic block pattern.

## Atomic block pattern

Every content block ships four or five artefacts in the same folder:

| File                  | Consumer                                | Purpose                                                                               |
| --------------------- | --------------------------------------- | ------------------------------------------------------------------------------------- |
| `block.json`          | Blockstudio / WP block editor           | Editor schema (attributes, supports, category)                                        |
| `index.php`           | WP front-end + editor SSR preview       | Render in WordPress contexts                                                          |
| `native.json`         | Mobile shell (`dispatch-block.blade.php`) | Shell contract: `dataSource`, `provides_context`, `consumes_context`, `field` |
| `mobile.blade.php`    | Mobile shell (projected to `components/mustuse/{slug}.blade.php`) | Render in NativePHP webview                              |
| `styles.inline.scss`  | Blockstudio compile + shell inlining   | Compiled to `_dist/styles.inline-{ts}.css`; mirrored to `public/blocks/{slug}/` on Ship |

The shell only ever sees `mobile.blade.php`, `native.json`, and the
compiled CSS / JS — `block.json` and `index.php` stay on the publisher.
This is what lets the same WordPress block editor drive both a public
website and a native app surface from one schema.

## Categories

### Static (render once from attributes)

`hero`, `text`, `image`, `video`, `quote`, `spacer`, `divider`,
`section`, `section-heading`, `breaking-banner`, `list`, `auth-gate`.

### Query (`dataSource` → live `/content` or `/terms`)

`article-list`, `article-grid`, `article-card`, `category-pills`,
`related-articles`, `trending`, `search-results`, `read-history-list`.

### Context (`consumes_context` → read the current route's post / term / author)

`post-title`, `post-content`, `post-excerpt`, `post-featured-image`,
`post-date`, `post-author-name`, `post-author-avatar`, `post-terms`,
`post-meta`, `post-reading-time`, `author-card`.

### Loop

`query-loop` container + `post-template` iteration marker (Gutenberg
`parent` lock; ours, not `core/query`).

### Content (WordPress sources)

- `wp-menu` — renders a WordPress-registered nav menu by theme location via `/menus/{location}`.
- `post-comments` — nested, paginated comment thread from `/comments/{post_id}`. Gated by App-level "Enable comments" toggle plus the post's own `comment_status`.

### Actions

- `native-action` — shell capability trigger. Expanded vocabulary covers
  camera, photo library, video record, scanner, biometrics, haptics,
  flashlight, geolocation, microphone, dialog alert, dialog toast,
  share, and the three browser variants. The `showResult` toggle
  renders the most recent native callback payload (path, coordinates,
  scanned data, …) inline — useful for the kitchen sink and debugging.
- `search` — input that navigates to `/search?q=…`.
- `bookmark-toggle` — mutating; local-first SecureStorage +
  `/bookmarks` sync.
- `push-enroll` — OS permission prompt + token sync to `/push/enroll`.

### Display (read-only, hydrated from shell state)

- `device-info` — platform, OS version, model, battery. Reads from
  `$shellState['deviceInfo']` populated by `Device::getInfo()` +
  `Device::getBatteryInfo()` on mount and on pull-to-refresh.
- `network-status` — connected / type / expensive / constrained flags
  from `Network::status()`. Same hydration pattern.

Both blocks fall back to a direct facade call if the shell forwards
only block attrs (legacy dispatcher).

### Canvas (admin-only)

`mustuse-apps-pub-canvas/branding-preview`,
`mustuse-apps-pub-canvas/mobile-preview`,
`mustuse-apps-pub-canvas/navigation-preview`,
`mustuse-apps-pub-canvas/manifest-preview`,
`mustuse-apps-pub-canvas/capability-status`. Server-rendered preview
blocks that appear on the App edit canvas. They read app meta to
display state — they never store it. Resolution uses `$b['postId']`
(Blockstudio's render context) with `get_the_ID()` as a fallback.

## Allowed-blocks policy

[`AllowedBlocks`](../src/Blocks/AllowedBlocks.php) gates the editor
palette per post type:

- **App canvas (`mua_app`)** — only `mustuse-apps-pub-canvas/*`. The
  app surface is for previews and configuration, not content.
- **Screen editor (`mua_app_screen`)** — every `mustuse-apps-pub/*`
  block plus a small core allowlist: `core/paragraph`, `core/heading`,
  `core/image`, `core/list`, `core/list-item`, `core/quote`,
  `core/separator`, `core/group`.

Extensions can extend the screen-editor allowlist via
`apply_filters('mua_allowed_blocks', $allowed, $context)`.

## Core block fallback

The shell renders core blocks via
[`livewire/partials/core-block.blade.php`](../assets/blueprints/mobile-shell/resources/views/livewire/partials/core-block.blade.php).
Supported: heading, paragraph, list, list-item, image, quote,
separator, spacer, html, group/columns/column. Unsupported core blocks
are skipped silently so older shells survive new core blocks shipping
in WordPress.

## Capabilities vocabulary

22 capabilities, three classifications (full registry in
[`CapabilityRegistry`](../src/Manifest/CapabilityRegistry.php)):

- **Block-classified (15):** `browser_inapp`, `browser_system`,
  `browser_auth`, `camera`, `photo_library`, `video_record`, `scanner`,
  `share`, `biometrics`, `haptics`, `flashlight`, `geolocation`,
  `microphone`, `dialog_alert`, `dialog_toast`. Valid values on a
  `native-action` block's `capability` attribute.
- **Shell (6):** `secure_storage`, `network`, `dialog`, `device`,
  `file`, `system`, `push_notifications`. Not authorable directly on
  `native-action`; surfaced through dedicated blocks
  (`device-info`, `network-status`, `push-enroll`,
  `bookmark-toggle`) or read by the shell at runtime.
- **Deferred (1):** `nfc` — reserved vocabulary; no v1 block.

The shell advertises which native capabilities it supports via
`POST /apps/{slug}/capabilities`. The manifest's `requiredCapabilities`
is filtered to only what both sides agree on, and blocks whose
required capability is not advertised are stripped from the projected
block tree.

## Kitchen-sink demo

[`KitchenSinkPattern`](../src/Blocks/KitchenSinkPattern.php) registers
a WordPress block pattern that wires every block-classified capability
plus the display + push + auth-gate blocks onto a single screen. The
[`KitchenSinkScaffold`](../src/Admin/KitchenSinkScaffold.php) creates
an App + home Screen containing that pattern and seeds a fake shell
advertisement covering every capability — one click from the empty
apps list gives a working end-to-end demo.

## Native capability round-trip

Capability triggers in `native-action` call
`NativeEdge::triggerCapability($cap, $slot, $payload, $options)` on the
shell side. The shell dispatches to the matching NativePHP facade,
then parks the async result on `$lastCallback[$capability]` via the
appropriate `#[OnNative(EventClass)]` listener:

| Capability       | Facade                        | Result event                                |
| ---------------- | ----------------------------- | ------------------------------------------- |
| `camera`         | `Camera::getPhoto()`          | `Events\Camera\PhotoTaken`                  |
| `photo_library`  | `Camera::pickImages(...)`     | `Events\Gallery\MediaSelected`              |
| `video_record`   | `Camera::recordVideo()`       | `Events\Camera\VideoRecorded` / `Cancelled` |
| `scanner`        | `Scanner::scan()`             | `Events\Scanner\CodeScanned`                |
| `geolocation`    | `Geolocation::requestPermissions()` + `getCurrentPosition()` | `Events\Geolocation\LocationReceived` + `PermissionStatusReceived` + `PermissionRequestResult` |
| `microphone`     | `Microphone::record()->event(App\Events\AudioRecorded::class)->start()` | `App\Events\AudioRecorded` (publisher-owned) |
| `biometrics`     | `Biometrics::prompt(...)`     | `Events\Biometric\Completed`                |
| `haptics`        | `Haptics::vibrate()`          | no event                                    |
| `flashlight`     | `Device::flashlight()`        | no event (toggle state kept locally)        |
| `dialog_alert`   | `Dialog::alert(...)`          | `Events\Alert\ButtonPressed`                |
| `dialog_toast`   | `Dialog::toast(...)`          | no event                                    |
| `share`          | `Share::url(...)`             | no event                                    |
| `browser_*`      | `Browser::open(...)`          | no event                                    |

Display blocks (`device-info`, `network-status`) don't trigger events —
they read the `deviceInfo` / `networkStatus` snapshots NativeEdge
refreshes on mount + pull-to-refresh.

## Block schema audit

[`BlockSchemaAuditor`](../src/Blocks/BlockSchemaAuditor.php) statically
checks every block's `block.json` against its `mobile.blade.php` and
flags drift (e.g. `block.json` declares `heading` but Blade reads
`title`). Findings surface in the App edit screen's Ship Readiness
metabox and in the build pipeline; setting `MUA_STRICT_BLOCK_AUDIT`
makes a finding fatal so CI can block a broken Ship.
