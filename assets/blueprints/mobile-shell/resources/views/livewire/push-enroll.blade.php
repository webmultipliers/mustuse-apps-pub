<section class="mua-push-enroll mua-push-enroll--{{ $state }}">
    <h3 class="mua-push-enroll__title">{{ $title }}</h3>

    @if ($state === 'enrolled')
        <p class="mua-push-enroll__status">✓ Notifications are on for this device.</p>
    @elseif ($state === 'declined')
        <p class="mua-push-enroll__status">Notifications weren't enabled. Open iOS / Android settings to turn them on later.</p>
    @else
        <p class="mua-push-enroll__description">{{ $description }}</p>
        <button
            type="button"
            class="mua-push-enroll__enable"
            wire:click="enroll"
            wire:loading.attr="disabled"
            @if ($state === 'pending') disabled @endif>
            {{ $state === 'pending' ? 'Requesting…' : $enableLabel }}
        </button>
    @endif
</section>
