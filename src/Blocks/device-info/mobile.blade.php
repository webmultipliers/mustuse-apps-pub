@props(['block' => [], 'children' => [], 'gates' => [], 'shellState' => []])

@php
    $title        = (string) ($block['title']        ?? 'Device');
    $showBattery  = (bool)   ($block['showBattery']  ?? true);
    $showPlatform = (bool)   ($block['showPlatform'] ?? true);
    $showModel    = (bool)   ($block['showModel']    ?? true);

    // Hydrate from parent NativeEdge snapshot — kept current by mount +
    // pull-to-refresh. Falls back to a direct facade call if the shell
    // didn't pre-populate (e.g. a legacy shell forwarding block-only).
    $info = is_array($shellState['deviceInfo'] ?? null) ? $shellState['deviceInfo'] : [];
    if ($info === [] && \class_exists('\Native\Mobile\Facades\Device')) {
        try { $info                     = (array) \Native\Mobile\Facades\Device::getInfo(); }        catch (\Throwable $e) { $info = []; }
        try { $info['battery'] ??= (array) \Native\Mobile\Facades\Device::getBatteryInfo(); } catch (\Throwable $e) {}
    }
    $battery = \is_array($info['battery'] ?? null) ? $info['battery'] : [];

    $platform = (string) ($info['platform']  ?? $info['os'] ?? '—');
    $osVer    = (string) ($info['osVersion'] ?? $info['os_version'] ?? '');
    $model    = (string) ($info['model']     ?? $info['deviceModel'] ?? '—');
    $pctRaw   = $battery['level'] ?? $battery['percent'] ?? null;
    $pct      = \is_numeric($pctRaw) ? (int) round(((float) $pctRaw) * ((float) $pctRaw <= 1 ? 100 : 1)) : null;
    $charging = (bool) ($battery['isCharging'] ?? $battery['charging'] ?? false);
@endphp

<div class="mua-device-info" role="group" aria-label="{{ $title }}">
    <h3 class="mua-device-info__title">{{ $title }}</h3>
    <dl class="mua-device-info__grid">
        @if ($showPlatform)
            <dt>Platform</dt>
            <dd>{{ $platform }}{{ $osVer !== '' ? ' ' . $osVer : '' }}</dd>
        @endif
        @if ($showModel)
            <dt>Model</dt>
            <dd>{{ $model }}</dd>
        @endif
        @if ($showBattery)
            <dt>Battery</dt>
            <dd>
                {{ $pct !== null ? $pct . '%' : '—' }}
                @if ($charging)
                    <span class="mua-device-info__charging" aria-label="charging">⚡</span>
                @endif
            </dd>
        @endif
    </dl>
</div>
