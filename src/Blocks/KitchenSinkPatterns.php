<?php

declare(strict_types=1);

namespace MustUse\Pub\Blocks;

/**
 * Per-screen block markup for the kitchen-sink demo app.
 *
 * Each public method returns the `post_content` of one screen in the
 * multi-screen capability tour. `KitchenSinkScaffold` stitches these
 * into a full app by wiring them to screens with the right routing +
 * fallback metadata.
 *
 * Every native-action on a capability page runs with `showResult: true`
 * so publishers exploring the demo see the round-trip payload
 * (photo path, scanned code, coordinates, etc.) inline.
 */
final class KitchenSinkPatterns
{
    public static function home(): string
    {
        return \implode("\n\n", [
            self::block('hero', [
                'heading'    => 'Kitchen Sink',
                'subheading' => 'Every native capability the publisher surfaces, split across self-contained demo pages. Tap a tile to try it.',
            ]),
            self::heading('Pick a capability to try'),
            self::navCard('Capture',          'Camera, photo library, video, scanner', '/capture'),
            self::navCard('Location',         'Geolocation permission flow + position', '/location'),
            self::navCard('Audio',            'Microphone recording with playback',     '/audio'),
            self::navCard('Sensors',          'Haptics + flashlight toggle',            '/sensors'),
            self::navCard('Dialogs & share',  'Native alerts, toasts, share sheet',     '/dialogs'),
            self::navCard('Browser',          'In-app, system, and OAuth browsers',     '/browser'),
            self::navCard('Auth',             'Biometrics + role/capability gates',     '/auth'),
            self::navCard('Device & network', 'Read-only live device/network data',     '/device'),
            self::navCard('Push',             'OS push-permission + token enroll',      '/push'),
        ]);
    }

    public static function capture(): string
    {
        return \implode("\n\n", [
            self::hero('Capture', 'Camera, photo library, video recording, and QR/barcode scanning. Each result renders inline after the native round-trip.'),
            self::section('Camera', 'Opens the native camera UI and returns the captured photo path on completion.'),
            self::action('camera',        'Take a photo',            'photo.capture',  [ 'showResult' => true ]),
            self::section('Photo library', 'Picks a single image from the device gallery.'),
            self::action('photo_library', 'Pick from library',       'photo.library',  [ 'showResult' => true ]),
            self::section('Video recording', 'Records a video and returns its saved path.'),
            self::action('video_record',  'Record a video',          'video.capture',  [ 'showResult' => true ]),
            self::section('Scanner', 'Reads QR + barcodes. Result includes the scanned text and format.'),
            self::action('scanner',       'Scan a code',             'scanner.result', [ 'showResult' => true ]),
        ]);
    }

    public static function location(): string
    {
        return \implode("\n\n", [
            self::hero('Location', 'Geolocation follows a permission → request → position flow. Permission results land on `geolocation_permission`; the position on `geolocation`.'),
            self::section('Current location', 'Requests permissions (if needed) and fetches the current position. Returns lat/lng/accuracy.'),
            self::action('geolocation',   'Get my location',         'geolocation',     [ 'showResult' => true ]),
        ]);
    }

    public static function audio(): string
    {
        return \implode("\n\n", [
            self::hero('Audio', 'Microphone recording returns a file path on completion. Combine with native-action `share` to share the recording.'),
            self::section('Record audio', 'Opens the native mic recorder. Tap again to re-record.'),
            self::action('microphone',    'Record audio',            'microphone.file', [ 'showResult' => true ]),
        ]);
    }

    public static function sensors(): string
    {
        return \implode("\n\n", [
            self::hero('Sensors', 'Haptic feedback + flashlight toggle. No permission prompt needed on device.'),
            self::section('Haptics', 'Triggers a short vibration. Intensity hint is stored on the block for future NativePHP API support.'),
            self::action('haptics',       'Vibrate',                 'haptics',        [ 'intensity' => 'medium', 'showResult' => true ]),
            self::section('Flashlight', 'Toggles the device torch. State is kept locally so the label reflects the last on/off.'),
            self::action('flashlight',    'Toggle flashlight',       'flashlight',      [ 'showResult' => true ]),
        ]);
    }

    public static function dialogs(): string
    {
        return \implode("\n\n", [
            self::hero('Dialogs & share', 'Native system dialogs — alerts, toasts, and the share sheet. All three fire synchronously; only the alert reports a result (the tapped button).'),
            self::section('Alert', 'Blocking modal alert with OK/Cancel.'),
            self::action('dialog_alert',  'Show alert',              'dialog.alert',  [ 'message' => 'Hello from the kitchen sink.', 'showResult' => true ]),
            self::section('Toast', 'Non-blocking transient message.'),
            self::action('dialog_toast',  'Show toast',              'dialog.toast',  [ 'message' => 'Saved.' ]),
            self::section('Share', 'Opens the OS share sheet with a URL payload.'),
            self::action('share',         'Share a URL',             'share',         [ 'message' => 'https://mustuse.com' ]),
        ]);
    }

    public static function browser(): string
    {
        return \implode("\n\n", [
            self::hero('Browser', 'Three ways to open a URL. `browser_inapp` is modal + dismissable; `browser_system` hands off to Safari/Chrome; `browser_auth` opens a sandboxed browser for OAuth flows.'),
            self::section('In-app browser', 'Opens in a sheet over the app. Dismiss returns to the current screen.'),
            self::action('browser_inapp',  'Open in-app',            'browser.inapp',  [ 'message' => 'https://mustuse.com' ]),
            self::section('System browser', 'Hands off to the default browser app.'),
            self::action('browser_system', 'Open system browser',    'browser.system', [ 'message' => 'https://mustuse.com' ]),
            self::section('OAuth browser', 'Sandboxed browser for sign-in flows; returns when the OAuth redirect hits a registered scheme.'),
            self::action('browser_auth',   'Open OAuth browser',     'browser.auth',   [ 'message' => 'https://mustuse.com/auth' ]),
        ]);
    }

    public static function auth(): string
    {
        return \implode("\n\n", [
            self::hero('Auth', 'Four flavours of gating. Biometrics prompts device-local auth; login / role / capability gates evaluate against the app\'s `subscriber_state` without round-tripping.'),
            self::section('Biometric prompt', 'Standalone Face ID / Touch ID prompt. Result shows whether the user succeeded or cancelled.'),
            self::action('biometrics', 'Authenticate', 'biometrics.prompt', [ 'message' => 'Unlock to continue', 'showResult' => true ]),
            self::section('Biometric gate', 'Wraps content behind biometric auth — children render after a successful prompt.'),
            self::authGate('biometrics', 'Biometric gate', 'Unlock to see the hidden message.', '<p>Unlocked — biometrics passed.</p>'),
            self::section('Login gate', 'Requires any logged-in subscriber. Tries `wp_get_current_user()` via manifest `subscriber_state`.'),
            self::authGate('login', 'Login required', 'Sign in to see subscriber-only content.', '<p>You are signed in.</p>'),
            self::section('Role gate', 'Requires the `subscriber` role. Extension-contributable via `mua_app_subscriber_state`.'),
            self::authGate('role', 'Subscriber only', 'Only accounts with the subscriber role see this.', '<p>Welcome, subscriber.</p>', [ 'requiredRole' => 'subscriber' ]),
            self::section('Capability gate', 'Requires the `edit_posts` capability.'),
            self::authGate('capability', 'Editor only', 'Requires edit_posts capability.', '<p>You can edit posts.</p>', [ 'requiredCapability' => 'edit_posts' ]),
        ]);
    }

    public static function device(): string
    {
        return \implode("\n\n", [
            self::hero('Device & network', 'Read-only display blocks. `device-info` + `network-status` hydrate from the shell\'s parent state on mount and on pull-to-refresh.'),
            self::section('Device info', 'Platform, OS version, model, battery.'),
            self::block('device-info',    [ 'title' => 'Device' ]),
            self::section('Network status', 'Connected + type + expensive/constrained flags.'),
            self::block('network-status', [ 'title' => 'Network' ]),
        ]);
    }

    public static function push(): string
    {
        return \implode("\n\n", [
            self::hero('Push notifications', 'Opt-in prompt + token sync. The shell POSTs the FCM/APNS token to `/push/enroll` keyed by device id.'),
            self::section('Enroll this device', 'Fires the OS permission flow; syncs the returned token to the pub.'),
            self::block('push-enroll', [
                'title'       => 'Stay in the loop',
                'description' => 'Allow notifications to receive breaking updates.',
                'enableLabel' => 'Enable notifications',
            ]),
        ]);
    }

    public static function notFound(): string
    {
        return \implode("\n\n", [
            self::block('section-heading', [ 'text' => 'We couldn\'t find that', 'level' => 'h2' ]),
            self::block('text', [
                'content' => '<p>That page isn\'t part of the kitchen-sink tour. Head back to the home screen or pick a capability below.</p>',
                'align'   => 'left',
            ]),
            self::heading('Capabilities'),
            self::navCard('Home',      'Back to the tour index', '/'),
            self::navCard('Capture',   'Camera / scanner / video', '/capture'),
            self::navCard('Dialogs',   'Alerts, toasts, share',    '/dialogs'),
        ]);
    }

    /**
     * Section header — large hero for each capability page so the
     * tour feels like discrete "pages" rather than a long list.
     */
    private static function hero(string $heading, string $sub): string
    {
        return self::block('hero', [
            'heading'    => $heading,
            'subheading' => $sub,
        ]);
    }

    private static function section(string $title, string $description): string
    {
        return \implode("\n", [
            self::block('section-heading', [ 'text' => $title, 'level' => 'h3' ]),
            self::block('text',            [ 'content' => '<p>' . $description . '</p>', 'align' => 'left' ]),
        ]);
    }

    private static function heading(string $text): string
    {
        return self::block('section-heading', [ 'text' => $text, 'level' => 'h3' ]);
    }

    /**
     * In-app navigation card. Uses the `screen-link` block, which
     * renders as `<a href="..." wire:navigate>` — Livewire's in-app
     * router. Critically NOT `browser_inapp`: that capability opens
     * the native device browser, which can't resolve app-local paths
     * like `/capture`.
     */
    private static function navCard(string $label, string $sub, string $path): string
    {
        return self::block('screen-link', [
            'label'       => $label,
            'description' => $sub,
            'path'        => $path,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function action(string $capability, string $label, string $slot, array $extra = []): string
    {
        return self::block('native-action', \array_merge([
            'capability'   => $capability,
            'label'        => $label,
            'callbackType' => 'state',
            'callbackSlot' => $slot,
            'feedback'     => 'none',
        ], $extra));
    }

    /**
     * Auth-gate with inline success content.
     *
     * @param array<string, mixed> $extra
     */
    private static function authGate(string $kind, string $heading, string $desc, string $unlockedHtml, array $extra = []): string
    {
        $attrs = \array_merge([
            'gateKind'        => $kind,
            'gateHeading'     => $heading,
            'gateDescription' => $desc,
            'ctaLabel'        => 'Unlock',
        ], $extra);

        $inner = self::block('text', [ 'content' => $unlockedHtml, 'align' => 'left' ]);

        return \sprintf(
            "<!-- wp:mustuse-apps-pub/auth-gate %s -->\n%s\n<!-- /wp:mustuse-apps-pub/auth-gate -->",
            wp_json_encode($attrs),
            $inner
        );
    }

    /**
     * Self-closing block comment with JSON-encoded attributes.
     *
     * @param array<string, mixed> $attrs
     */
    private static function block(string $slug, array $attrs): string
    {
        $json = empty($attrs) ? '' : ' ' . wp_json_encode($attrs);
        return \sprintf('<!-- wp:mustuse-apps-pub/%s%s /-->', $slug, $json);
    }
}
