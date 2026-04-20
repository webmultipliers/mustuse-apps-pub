@props(['block' => [], 'children' => [], 'gates' => [], 'context' => null, 'shellState' => []])

@php
    $heading        = (string) ($block['gateHeading']     ?? 'Authentication required');
    $description    = (string) ($block['gateDescription'] ?? '');
    $ctaLabel       = (string) ($block['ctaLabel']        ?? 'Unlock');
    $kind           = (string) ($block['gateKind']        ?? 'biometrics');
    $requiredRole   = (string) ($block['requiredRole']    ?? '');
    $requiredCap    = (string) ($block['requiredCapability'] ?? '');
    $gateId         = 'gate-' . substr(md5($heading . json_encode($children)), 0, 8);
    $children       = is_array($children) ? $children : [];

    // Role/capability gates resolve from the manifest's subscriber_state —
    // which lives on the shell as $manifest->subscriber_state — forwarded
    // here via shellState by NativeEdge so the gate renders without a
    // round-trip.
    $sub   = is_array($shellState['subscriberState'] ?? null) ? $shellState['subscriberState'] : [];
    $roles = is_array($sub['roles']        ?? null) ? $sub['roles']        : [];
    $caps  = is_array($sub['capabilities'] ?? null) ? $sub['capabilities'] : [];

    $isOpen = false;
    switch ($kind) {
        case 'biometrics':
            $isOpen = ! empty($gates[$gateId]);
            break;
        case 'login':
            $isOpen = ! empty($sub['logged_in']);
            break;
        case 'role':
            $isOpen = $requiredRole !== '' && in_array($requiredRole, $roles, true);
            break;
        case 'capability':
            $isOpen = $requiredCap !== '' && in_array($requiredCap, $caps, true);
            break;
    }
@endphp

<section class="mua-auth-gate" data-gate-id="{{ $gateId }}" data-gate-kind="{{ $kind }}" data-open="{{ $isOpen ? 'true' : 'false' }}">
    @if ($isOpen)
        @foreach ($children as $child)
            @include('livewire.partials.dispatch-block', [
                'block'      => $child,
                'context'    => $context,
                'gates'      => $gates,
                'shellState' => $shellState,
            ])
        @endforeach
    @else
        <div class="mua-auth-gate__prompt">
            <h3 class="mua-auth-gate__heading">{{ $heading }}</h3>
            @if ($description !== '')
                <p class="mua-auth-gate__description">{{ $description }}</p>
            @endif
            @if ($kind === 'biometrics')
                <button type="button"
                        class="mua-auth-gate__unlock"
                        wire:click="triggerAuthGate('{{ $gateId }}')">
                    {{ $ctaLabel }}
                </button>
            @else
                <p class="mua-auth-gate__description">
                    {{ $kind === 'login' ? __('Sign in to view this content.') : __('Your account doesn\'t have access to this content.') }}
                </p>
            @endif
        </div>
    @endif
</section>
