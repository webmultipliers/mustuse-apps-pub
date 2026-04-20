@props(['block' => [], 'children' => [], 'gates' => [], 'shellState' => []])

@php
    $title       = (string) ($block['title']       ?? 'Network');
    $offlineText = (string) ($block['offlineText'] ?? "You're offline.");
    $showType    = (bool)   ($block['showType']    ?? true);
    $showFlags   = (bool)   ($block['showFlags']   ?? true);

    $status = is_array($shellState['networkStatus'] ?? null) ? $shellState['networkStatus'] : [];
    if ($status === [] && \class_exists('\Native\Mobile\Facades\Network')) {
        try { $status = (array) \Native\Mobile\Facades\Network::status(); } catch (\Throwable $e) { $status = []; }
    }

    $connected   = (bool)   ($status['connected']   ?? $status['isConnected']    ?? false);
    $type        = (string) ($status['type']        ?? $status['connectionType'] ?? '—');
    $expensive   = (bool)   ($status['expensive']   ?? $status['isExpensive']    ?? false);
    $constrained = (bool)   ($status['constrained'] ?? $status['isConstrained']  ?? false);

    $modifier = $connected ? 'online' : 'offline';
@endphp

<div class="mua-network-status mua-network-status--{{ $modifier }}" role="status" aria-live="polite">
    <span class="mua-network-status__dot" aria-hidden="true"></span>
    <div class="mua-network-status__body">
        <h3 class="mua-network-status__title">{{ $title }}</h3>
        @if ($connected)
            <p class="mua-network-status__line">
                @if ($showType) {{ $type }} @endif
                @if ($showFlags && ($expensive || $constrained))
                    <span class="mua-network-status__flags">
                        @if ($expensive)   <span class="mua-network-status__flag">expensive</span> @endif
                        @if ($constrained) <span class="mua-network-status__flag">constrained</span> @endif
                    </span>
                @endif
            </p>
        @else
            <p class="mua-network-status__line">{{ $offlineText }}</p>
        @endif
    </div>
</div>
