<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Events\AudioRecorded;
use Livewire\Component;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\Alert\ButtonPressed as AlertButtonPressed;
use Native\Mobile\Events\Biometric\Completed as BiometricCompleted;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Camera\VideoCancelled;
use Native\Mobile\Events\Camera\VideoRecorded;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult as GeoPermissionRequestResult;
use Native\Mobile\Events\Geolocation\PermissionStatusReceived as GeoPermissionStatusReceived;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Facades\Biometrics;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Camera;
use Native\Mobile\Facades\Scanner;
use Native\Mobile\Facades\Share;

/**
 * The shell's catch-all screen renderer.
 *
 * Reads `storage/app/mua-manifest.json` produced by the pub's BuildAssembler,
 * resolves the screen matching the current URL path, and hands the block
 * tree to the Blade template for rendering.
 *
 * Native capability triggers (`native-action` blocks) call into this
 * component via `wire:click="triggerCapability(...)"`. Asynchronous native
 * events route back via `#[OnNative]` listeners below and update Livewire
 * state so the Blade view re-renders with the result.
 */
class NativeEdge extends Component
{
    /**
     * Highest manifest schema version this shell knows how to render.
     * Bumped in lockstep with the publisher's ManifestBuilder::SCHEMA_VERSION
     * whenever a breaking change lands. Manifests with `min_shell_version`
     * above this number are refused at load time.
     */
    public const SUPPORTED_SCHEMA_VERSION = 2;

    public string $path = '/';

    /** @var array<string, mixed>|null */
    public ?array $screen = null;

    /** @var array<string, mixed>|null */
    public ?array $manifest = null;

    public string $title = '';

    /** @var array<int, array<string, mixed>> */
    public array $navTabs = [];

    /** @var array<int, array<string, mixed>> */
    public array $drawerScreens = [];

    /**
     * Publisher-curated flat side-navigation list. Distinct from
     * `$drawerScreens` (full hierarchical tree) — side-nav is an
     * opt-in, ordered subset of screens chosen by the publisher.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $sideNav = [];

    public string $message = '';

    /**
     * Biometric auth-gate state keyed by gate id. An auth-gate block hides
     * its inner blocks until `triggerAuthGate($id)` completes with success.
     *
     * @var array<string, bool>
     */
    public array $gates = [];

    /**
     * Last native callback payload keyed by capability, so native-action
     * blocks can display e.g. the path of the photo just taken.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $lastCallback = [];

    /**
     * Snapshot of `Device::getInfo()` + `Device::getBatteryInfo()` —
     * populated on mount and on `triggerRefresh()`. The device-info
     * display block reads from this bag rather than calling the facade
     * on every render, so Livewire re-renders don't trigger a native
     * bridge round-trip for unchanged data.
     *
     * @var array<string, mixed>
     */
    public array $deviceInfo = [];

    /**
     * Snapshot of `Network::status()`. Same pattern as `$deviceInfo` —
     * parent-owned state the network-status block consumes via props.
     *
     * @var array<string, mixed>
     */
    public array $networkStatus = [];

    /**
     * Route context (post / term / author / search) for the current URL.
     * Populated from the pub's /resolve-deeplink endpoint when the URL
     * doesn't exact-match a manifest screen. Context-bound blocks
     * (`post-title`, `post-featured-image`, …) read from here.
     *
     * @var array<string, mixed>|null
     */
    public ?array $context = null;

    public function mount(string $any = ''): void
    {
        // Preserve the query string — /search?q=… needs it for the
        // DeeplinkResolver; the Livewire router only forwards the path.
        $query      = \Illuminate\Support\Facades\Request::getQueryString();
        $basePath   = '/' . ltrim($any, '/');
        $this->path = $query !== null && $query !== '' ? $basePath . '?' . $query : $basePath;
        $this->loadManifest();
    }

    protected function loadManifest(): void
    {
        $manifestPath = storage_path('app/mua-manifest.json');
        $sigPath      = storage_path('app/mua-manifest.sig');

        if (! is_file($manifestPath)) {
            $this->message = 'Manifest not found. Project a build from the publisher and redeploy this shell.';
            return;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            $this->message = 'Could not read manifest file.';
            return;
        }

        // HMAC verification (fail-closed when key is configured). The pub's
        // BuildAssembler signs the exact bytes it wrote; we HMAC those same
        // bytes with MUA_APPKEY and reject tampered builds before any screen
        // data is trusted. Skipped only when MUA_APPKEY is absent (local dev).
        $appKey = env('MUA_APPKEY') ? trim((string) env('MUA_APPKEY')) : '';
        if ($appKey !== '') {
            if (! file_exists($sigPath)) {
                $this->message = 'Manifest signature missing. Refusing to render unsigned build.';
                return;
            }
            $providedSig = trim((string) file_get_contents($sigPath));
            $computedSig = hash_hmac('sha256', $raw, $appKey);
            if (! hash_equals($computedSig, $providedSig)) {
                $this->message = 'Manifest signature mismatch. Refusing to render tampered build.';
                return;
            }
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            $this->message = 'Manifest is not valid JSON.';
            return;
        }

        $minShell = (int) ($manifest['min_shell_version'] ?? 1);
        if ($minShell > self::SUPPORTED_SCHEMA_VERSION) {
            $this->message = sprintf(
                'Manifest needs schema v%d but this shell only understands v%d. Upgrade the shell binary.',
                $minShell,
                self::SUPPORTED_SCHEMA_VERSION,
            );
            return;
        }

        $this->manifest       = $manifest;
        $this->title          = (string) ($manifest['branding']['name'] ?? config('app.name', 'App'));
        $this->navTabs        = $this->resolveNavTabs($manifest);
        $this->drawerScreens  = $this->resolveDrawerScreens($manifest);
        $this->sideNav        = $this->resolveSideNav($manifest);
        $this->refreshDeviceState();
        $this->screen         = $this->resolveScreen($manifest, $this->path);

        // Fall back to the pub for anything that isn't an exact-match
        // screen (e.g. /article/{slug}). The resolver returns
        // {screen_id, context, match_strength} — `match_strength` lets
        // us flag soft matches in dev builds without changing behaviour.
        if (! $this->screen) {
            $resolved = $this->resolveDeeplink($this->path);
            if ($resolved !== null) {
                $screenId      = (string) ($resolved['screen_id'] ?? '');
                $this->context = \is_array($resolved['context'] ?? null) ? $resolved['context'] : null;
                $this->screen  = $this->findScreenById($manifest, $screenId);
            }
        }

        // Cascade didn't name a screen OR named one that isn't in the
        // manifest — consult the fallback_map + fallback_policy. This is
        // the guarantee that no request dead-ends with a raw error.
        if (! $this->screen) {
            $this->applyFallbackPolicy($manifest, $this->path);
            if ($this->screen) {
                return;
            }
        }

        $contextPostId = (int) \Illuminate\Support\Arr::get($this->context, 'post.id', 0);
        if ($contextPostId > 0) {
            try {
                app(\App\Services\Persistence::class)->recordVisit(
                    $contextPostId,
                    (string) \Illuminate\Support\Arr::get($this->context, 'post.post_type', 'post'),
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('recordVisit skipped', ['error' => $e->getMessage()]);
            }
        }

        if (! $this->screen) {
            $this->message = 'No screen configured for path ' . $this->path;
        }
    }

    /**
     * Final-floor fallback: never let a request dead-end. Priority:
     *  1. `fallback_map[any]` → render the authored 404 screen
     *  2. `fallback_policy.mode = web` + network available → WebView
     *  3. `fallback_policy.mode = home` → set screen to the home entry
     *  4. Offline + no authored 404 → message (unavoidable)
     *
     * Writes `$this->screen` / `$this->message` in place so the caller
     * continues the normal render path.
     *
     * @param array<string, mixed> $manifest
     */
    protected function applyFallbackPolicy(array $manifest, string $path): void
    {
        $fallbackMap = \is_array($manifest['fallback_map'] ?? null) ? $manifest['fallback_map'] : [];
        $anySlug     = isset($fallbackMap['any']) ? (string) $fallbackMap['any'] : '';

        $policy = \is_array($manifest['fallback_policy'] ?? null) ? $manifest['fallback_policy'] : [];
        $mode   = (string) ($policy['mode'] ?? 'screen_404');
        $base   = (string) ($policy['webview_base'] ?? '');

        // Authored 404 wins for modes that pin to a screen, and stays
        // the safe-harbour when `web` mode has no base or we're offline.
        if ($anySlug !== '' && \in_array($mode, ['screen_404', 'home'], true)) {
            $resolved = $this->findScreenById($manifest, $anySlug);
            if ($resolved) {
                $this->screen  = $resolved;
                $this->context = null;
                return;
            }
        }

        if ($mode === 'web' && $base !== '') {
            // Open the WP page in an in-app browser. This is modal — the
            // user sees a close chrome — but guarantees *something*
            // renders even if the content isn't authored natively.
            $target = rtrim($base, '/') . $path;
            $this->callIfExists('\Native\Mobile\Facades\Browser', 'inApp', [$target]);
            $this->message = 'Opening the web view for ' . $path;
            // Also land the authored 404 (if any) behind the browser
            // sheet so dismiss lands on a useful screen instead of void.
            if ($anySlug !== '') {
                $resolved = $this->findScreenById($manifest, $anySlug);
                if ($resolved) {
                    $this->screen  = $resolved;
                    $this->context = null;
                    return;
                }
            }
            return;
        }

        if ($mode === 'home') {
            $home = $this->resolveScreen($manifest, '/');
            if ($home) {
                $this->screen  = $home;
                $this->context = null;
                return;
            }
        }

        // Last-resort fallback to any authored 404 even if the policy
        // didn't specifically ask for it — better than the raw message.
        if ($anySlug !== '') {
            $resolved = $this->findScreenById($manifest, $anySlug);
            if ($resolved) {
                $this->screen  = $resolved;
                $this->context = null;
            }
        }
    }

    /**
     * Null on any failure — callers fall through to the "no screen
     * configured" message so a transient network blip reads as a benign
     * missing-route rather than an error dialog.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveDeeplink(string $path): ?array
    {
        $endpoints = $this->manifest['endpoints'] ?? [];
        $url       = is_array($endpoints) && isset($endpoints['resolve_deeplink'])
            ? (string) $endpoints['resolve_deeplink']
            : '';
        $appKey    = (string) env('MUA_APPKEY', '');
        if ($url === '' || $appKey === '') {
            // Single-warn so a misconfigured deploy is diagnosable in
            // logs. Without either value the cascade immediately drops
            // to the authored 404 / web fallback, which can look like
            // "deep links are broken" without an explanation.
            static $warned = false;
            if (! $warned) {
                $warned = true;
                \Illuminate\Support\Facades\Log::warning('resolve-deeplink skipped — MUA_APPKEY or endpoint missing', [
                    'endpoint_present' => $url !== '',
                    'appkey_present'   => $appKey !== '',
                ]);
            }
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $appKey,
                ])
                ->timeout(8)
                ->post($url, ['path' => $path]);
            if (! $response->successful()) {
                return null;
            }
            $body = $response->json();
            return is_array($body) ? $body : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('resolve-deeplink failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Look up a screen by its slug/id in the already-loaded manifest.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>|null
     */
    protected function findScreenById(array $manifest, string $screenId): ?array
    {
        if ($screenId === '') {
            return null;
        }
        $screens = $manifest['screens'] ?? [];
        if (! \is_array($screens)) {
            return null;
        }
        foreach ($screens as $screen) {
            if (! \is_array($screen)) {
                continue;
            }
            $candidate = (string) ($screen['id'] ?? $screen['slug'] ?? '');
            if ($candidate === $screenId) {
                return $screen;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    protected function resolveNavTabs(array $manifest): array
    {
        $nav = $manifest['navigation'] ?? [];
        $bottomNav = is_array($nav) && isset($nav['bottom_nav']) && is_array($nav['bottom_nav'])
            ? $nav['bottom_nav']
            : [];
        return array_values(array_filter($bottomNav, 'is_array'));
    }

    /**
     * Drawer entries come from `navigation.drawer` — a tree projected by
     * the publisher from every published screen. Sibling order follows
     * the screen's menu_order; children are preserved so the blade layer
     * can render nested sections.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    protected function resolveDrawerScreens(array $manifest): array
    {
        $nav = $manifest['navigation'] ?? [];
        if (! is_array($nav) || ! isset($nav['drawer']) || ! is_array($nav['drawer'])) {
            return [];
        }
        return array_values(array_filter($nav['drawer'], 'is_array'));
    }

    /**
     * Publisher-curated side-nav list (flat, ordered). Separate from the
     * hierarchical drawer — shells typically pick one based on form factor
     * or render both as a two-level navigation surface.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    protected function resolveSideNav(array $manifest): array
    {
        $nav = $manifest['navigation'] ?? [];
        if (! is_array($nav) || ! isset($nav['side_nav']) || ! is_array($nav['side_nav'])) {
            return [];
        }
        return array_values(array_filter($nav['side_nav'], 'is_array'));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    protected function resolveScreen(array $manifest, string $path): ?array
    {
        $screens = $manifest['screens'] ?? [];
        if (! is_array($screens)) {
            return null;
        }

        $pathOnly = strtok($path, '?');
        $target   = rtrim((string) $pathOnly, '/') ?: '/';

        foreach ($screens as $screen) {
            if (! is_array($screen)) {
                continue;
            }
            $screenPath = rtrim((string) ($screen['path'] ?? ''), '/') ?: '/';
            if ($screenPath === $target) {
                return $screen;
            }
        }

        if ($target === '/') {
            foreach ($screens as $screen) {
                if (is_array($screen) && ! empty($screen['is_home'])) {
                    return $screen;
                }
            }
        }

        return null;
    }

    // --- Capability triggers (called from native-action blocks) --------

    /**
     * Dispatch a native-action block to the matching NativePHP Mobile
     * facade. `$payload` carries a URL or message string depending on
     * the capability. `$options` is a free-form bag of extras (intensity,
     * feedback mode) so the block can stay expressive without growing
     * the signature on every new capability.
     *
     * Facades are resolved via `callIfExists` so a shell pinned to an
     * older NativePHP release degrades gracefully instead of fatally
     * booting — unmet capabilities simply no-op.
     *
     * @param array<string, mixed> $options
     */
    public function triggerCapability(string $capability, string $id = '', string $payload = '', array $options = []): void
    {
        switch ($capability) {
            case 'browser':
                if ($payload !== '') {
                    Browser::open($payload);
                }
                break;

            case 'camera':
                Camera::getPhoto();
                break;

            case 'photo_library':
                // Camera::pickImages($mediaType, $multiple, $maxItems) — verified
                // against NativePHP/kitchen-sink-mobile 2.x Gallery.php. Defaults
                // to a single-image pick since the block exposes no multi-select UI.
                $this->callIfExists('\Native\Mobile\Facades\Camera', 'pickImages', ['image', false, 1]);
                break;

            case 'video_record':
                // Returns a fluent recorder; the kitchen-sink demo omits ->start()
                // because the Camera facade auto-starts on first no-arg call when
                // maxDuration isn't set. Match that behaviour.
                $this->callIfExists('\Native\Mobile\Facades\Camera', 'recordVideo');
                break;

            case 'scanner':
                Scanner::scan();
                break;

            case 'share':
                if ($payload !== '') {
                    Share::url($payload);
                }
                break;

            case 'biometrics':
                Biometrics::prompt($payload !== '' ? $payload : 'Authenticate');
                break;

            case 'haptics':
                // NativePHP's `Haptics::vibrate()` takes no args in v2/v3 — an
                // intensity bag was my earlier guess. Kept the `intensity`
                // attribute on the block for when/if a future release adds it;
                // for now it's a no-op carried through to `lastCallback` so
                // blocks can still echo the chosen value.
                $this->lastCallback['haptics'] = ['intensity' => (string) ($options['intensity'] ?? 'medium')];
                $this->callIfExists('\Native\Mobile\Facades\Haptics', 'vibrate');
                break;

            case 'flashlight':
                // `Device::flashlight()` toggles; we mirror the toggle in state
                // so blocks can show the last known on/off without polling the
                // device (there's no flashlight-changed event exposed).
                $on = ! ($this->lastCallback['flashlight']['on'] ?? false);
                $this->lastCallback['flashlight'] = ['on' => $on];
                $this->callIfExists('\Native\Mobile\Facades\Device', 'flashlight');
                break;

            case 'geolocation':
                // iOS/Android require a one-shot permission prompt before a
                // position request; kitchen-sink splits this into two user
                // actions, but for a single-button block we fire both and let
                // the `LocationReceived` / permission events resolve async.
                $this->callIfExists('\Native\Mobile\Facades\Geolocation', 'requestPermissions');
                $this->callIfExists('\Native\Mobile\Facades\Geolocation', 'getCurrentPosition', [true]);
                break;

            case 'microphone':
                // Microphone::record() requires a consumer-supplied event class
                // (verified against kitchen-sink Microphone.php). `AudioRecorded`
                // is our shell-local class; the listener below receives it.
                $facade = '\Native\Mobile\Facades\Microphone';
                if (\class_exists($facade) && \method_exists($facade, 'record')) {
                    try {
                        $facade::record()->event(AudioRecorded::class)->start();
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('microphone record failed', ['error' => $e->getMessage()]);
                    }
                }
                break;

            case 'dialog_alert':
                $this->callIfExists('\Native\Mobile\Facades\Dialog', 'alert', [$payload !== '' ? $payload : 'Alert']);
                break;

            case 'dialog_toast':
                $this->callIfExists('\Native\Mobile\Facades\Dialog', 'toast', [$payload !== '' ? $payload : 'Done']);
                break;
        }

        // Feedback ladder — post-action UI confirmation. Skipped when the
        // capability is itself a dialog (user already saw a primary surface).
        $feedback = \is_string($options['feedback'] ?? null) ? $options['feedback'] : 'none';
        if ($feedback !== 'none' && ! \in_array($capability, ['dialog_alert', 'dialog_toast'], true)) {
            $method = $feedback === 'alert' ? 'alert' : 'toast';
            $this->callIfExists('\Native\Mobile\Facades\Dialog', $method, [\ucfirst($capability) . ' done']);
        }
    }

    /**
     * Invoke a static method on a NativePHP facade only if both the class
     * and method exist in the installed version. Returns the method's
     * return value on success, null otherwise, so callers can chain a
     * fallback with `?:`.
     *
     * @param array<int, mixed> $args
     */
    protected function callIfExists(string $facade, string $method, array $args = []): mixed
    {
        if (! \class_exists($facade) || ! \method_exists($facade, $method)) {
            return null;
        }
        try {
            return $facade::$method(...$args);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('native facade call failed', [
                'facade' => $facade,
                'method' => $method,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function triggerAuthGate(string $gateId): void
    {
        $this->gates[$gateId] = false;
        Biometrics::prompt('Unlock ' . $gateId);
    }

    /**
     * Fired by pull-to-refresh. Every DynamicBlock and QueryLoop on the
     * screen listens for `block-refresh` and drops its PubClient cache
     * entry in response. Also refreshes device + network snapshots so
     * the display blocks reflect the current state.
     */
    public function triggerRefresh(): void
    {
        $this->refreshDeviceState();
        $this->dispatch('block-refresh');
    }

    /**
     * Re-read Device + Network facades into component state. NativePHP
     * Mobile v3 doesn't expose NetworkChanged / BatteryChanged listeners,
     * so we hydrate on mount + pull-to-refresh + after every capability
     * trigger. Facades that aren't present (older shell build) just leave
     * the snapshot empty — the blade side falls back to placeholders.
     */
    protected function refreshDeviceState(): void
    {
        $device  = $this->callIfExists('\Native\Mobile\Facades\Device', 'getInfo');
        $battery = $this->callIfExists('\Native\Mobile\Facades\Device', 'getBatteryInfo');
        $network = $this->callIfExists('\Native\Mobile\Facades\Network', 'status');

        $this->deviceInfo    = \array_merge(
            \is_array($device)  ? $device  : [],
            \is_array($battery) ? ['battery' => $battery] : [],
        );
        $this->networkStatus = \is_array($network) ? $network : [];
    }

    // --- Native event listeners (async completion callbacks) -----------

    #[OnNative(PhotoTaken::class)]
    public function onPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        $this->lastCallback['camera'] = [
            'path'     => $path,
            'mimeType' => $mimeType,
            'id'       => $id,
        ];
    }

    #[OnNative(CodeScanned::class)]
    public function onCodeScanned(string $data, string $format, ?string $id = null): void
    {
        $this->lastCallback['scanner'] = [
            'data'   => $data,
            'format' => $format,
            'id'     => $id,
        ];
    }

    #[OnNative(BiometricCompleted::class)]
    public function onBiometricCompleted(bool $success, ?string $id = null): void
    {
        $this->lastCallback['biometrics'] = [
            'success' => $success,
            'id'      => $id,
        ];
        if ($id !== null && $id !== '') {
            $this->gates[$id] = $success;
        }
    }

    #[OnNative(VideoRecorded::class)]
    public function onVideoRecorded(string $path, ?string $mimeType = null, ?string $id = null): void
    {
        $this->lastCallback['video_record'] = [
            'path'     => $path,
            'mimeType' => $mimeType,
            'id'       => $id,
        ];
    }

    #[OnNative(VideoCancelled::class)]
    public function onVideoCancelled(): void
    {
        $this->lastCallback['video_record'] = [
            'path'      => null,
            'cancelled' => true,
        ];
    }

    /**
     * The Gallery event ships `(success, files[], count)` — `files` is an
     * array of `{path, type}` entries. We pack the whole payload onto the
     * callback slot and let the block-side renderer extract what it needs.
     *
     * @param array<int, array<string, mixed>> $files
     */
    #[OnNative(MediaSelected::class)]
    public function onMediaSelected(bool $success, array $files = [], int $count = 0): void
    {
        $this->lastCallback['photo_library'] = [
            'success' => $success,
            'files'   => $files,
            'count'   => $count,
        ];
    }

    #[OnNative(LocationReceived::class)]
    public function onLocationReceived(
        ?bool $success = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $accuracy = null,
        ?int $timestamp = null,
        ?string $provider = null,
        ?string $error = null,
    ): void {
        $this->lastCallback['geolocation'] = [
            'success'   => $success,
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'accuracy'  => $accuracy,
            'timestamp' => $timestamp,
            'provider'  => $provider,
            'error'     => $error,
        ];
    }

    #[OnNative(GeoPermissionStatusReceived::class)]
    public function onGeoPermissionStatus(?string $location = null, ?string $coarseLocation = null, ?string $fineLocation = null): void
    {
        $this->lastCallback['geolocation_permission'] = [
            'location'        => $location,
            'coarseLocation'  => $coarseLocation,
            'fineLocation'    => $fineLocation,
        ];
    }

    #[OnNative(GeoPermissionRequestResult::class)]
    public function onGeoPermissionRequest(
        ?string $location = null,
        ?string $coarseLocation = null,
        ?string $fineLocation = null,
        ?string $message = null,
        ?bool $needsSettings = null,
    ): void {
        $this->lastCallback['geolocation_permission'] = [
            'location'       => $location,
            'coarseLocation' => $coarseLocation,
            'fineLocation'   => $fineLocation,
            'message'        => $message,
            'needsSettings'  => $needsSettings,
        ];
    }

    /**
     * Fires when the native `Microphone::record()->event(AudioRecorded)->start()`
     * call completes. Signature mirrors kitchen-sink's handler so the
     * NativePHP-side argument tuple stays compatible.
     */
    #[OnNative(AudioRecorded::class)]
    public function onAudioRecorded(string $path, ?string $mimeType = null, ?string $id = null): void
    {
        $this->lastCallback['microphone'] = [
            'path'     => $path,
            'mimeType' => $mimeType,
            'id'       => $id,
        ];
    }

    /**
     * Dialog alert button tap. The shell can't know which logical block
     * opened the alert, so we key the callback slot by the returned id
     * (NativePHP sets this when the caller passed one). Generic "alert
     * was dismissed" consumers just read `dialog_alert`.
     */
    #[OnNative(AlertButtonPressed::class)]
    public function onAlertButtonPressed(?string $button = null, ?string $id = null): void
    {
        $this->lastCallback['dialog_alert'] = [
            'button' => $button,
            'id'     => $id,
        ];
    }

    public function render()
    {
        $collector = new \App\Support\BlockAssetCollector(
            $this->screen['block_tree'] ?? null,
        );

        // Use the resolved post title for the window title on detail
        // screens so the status bar reflects what's being read.
        $effectiveTitle = (string) (data_get($this->context, 'post.title') ?: $this->title);

        return view('livewire.native-edge')
            ->layoutData([
                'title'             => $effectiveTitle,
                'manifest'          => $this->manifest,
                'context'           => $this->context,
                'inlineBlockCss'    => $collector->inlineCss(),
                'inlineBlockJs'     => $collector->inlineJs(),
                'presentBlockSlugs' => $collector->slugs(),
            ]);
    }
}
