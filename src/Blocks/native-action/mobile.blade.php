@props(['block' => [], 'children' => [], 'gates' => [], 'context' => null, 'shellState' => []])

@php
    // Lightweight `{{ context.post.field }}` interpolator so publishers
    // can share the current post's URL, dialog the current post's title,
    // etc. Only operates on strings; context arrays are looked up by
    // dot-path. Missing keys collapse to empty string.
    $interpolate = static function (string $raw) use ($context): string {
        if ($context === null || ! is_array($context) || strpos($raw, '{{') === false) {
            return $raw;
        }
        return (string) preg_replace_callback(
            '/\{\{\s*context\.([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $m) use ($context): string {
                $value = $context;
                foreach (explode('.', $m[1]) as $segment) {
                    if (is_array($value) && array_key_exists($segment, $value)) {
                        $value = $value[$segment];
                        continue;
                    }
                    return '';
                }
                return is_scalar($value) ? (string) $value : '';
            },
            $raw,
        );
    };

    $capability   = $block['capability']   ?? '';
    $label        = $block['label']        ?? ucfirst((string) $capability);
    $message      = $interpolate((string) ($block['message']      ?? ''));
    $intensity    = (string) ($block['intensity']    ?? 'medium');
    $feedback     = (string) ($block['feedback']     ?? 'none');
    $fallbackText = (string) ($block['fallbackText'] ?? '');
    $showResult   = (bool)   ($block['showResult']   ?? false);
    $callbackType = (string) ($block['callbackType'] ?? 'state');
    $callbackSlot = (string) ($block['callbackSlot'] ?? '');
    $callbackUrl  = $interpolate((string) ($block['callbackUrl']  ?? ''));

    // Browser variants share one shell handler; the variant just
    // determines how the URL opens. Preserves the original contract
    // for shells that haven't upgraded to the extended switch.
    $shellCapability = match ($capability) {
        'browser_inapp', 'browser_system', 'browser_auth' => 'browser',
        default                                           => $capability,
    };

    // Capabilities that take a string payload (URL / message / seed text).
    // Browser + share reuse `callbackUrl` for back-compat; the newer
    // dialog/scanner/geolocation paths read from `message`.
    $payload = match ($capability) {
        'browser_inapp', 'browser_system', 'browser_auth', 'share' => $callbackUrl !== '' ? $callbackUrl : $message,
        default                                                    => $message,
    };

    // Result row reads `$shellState['lastCallback'][<capability>]` from
    // the parent NativeEdge — populated by the matching #[OnNative]
    // listener after the native round-trip completes. Displayed as a
    // shallow key/value list because there's no per-capability UI
    // template here — publishers wanting richer output build their own
    // block.
    $resultKey    = $capability;
    $lastCallback = is_array($shellState['lastCallback'] ?? null) ? $shellState['lastCallback'] : [];
    $result       = is_array($lastCallback[$resultKey] ?? null) ? $lastCallback[$resultKey] : [];
@endphp

@if ($capability !== '')
    <button type="button"
            class="mua-native-action mua-native-action--{{ $capability }}"
            wire:click="triggerCapability(@js($shellCapability), @js($callbackSlot), @js($payload), @js(['intensity' => $intensity, 'feedback' => $feedback]))">
        <span class="mua-native-action__label">{{ $label }}</span>
    </button>

    @if ($showResult && $result !== [])
        <dl class="mua-native-action__result" aria-live="polite">
            @foreach ($result as $k => $v)
                <dt>{{ $k }}</dt>
                <dd>{{ is_scalar($v) ? (string) $v : json_encode($v) }}</dd>
            @endforeach
        </dl>
    @endif

    @if ($fallbackText !== '')
        <noscript class="mua-native-action__fallback">{{ $fallbackText }}</noscript>
    @endif
@endif
