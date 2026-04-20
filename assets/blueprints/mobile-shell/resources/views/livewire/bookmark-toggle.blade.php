<button
    type="button"
    class="mua-bookmark-toggle {{ $bookmarked ? 'is-active' : '' }}"
    wire:click="toggle"
    wire:loading.attr="disabled"
    aria-pressed="{{ $bookmarked ? 'true' : 'false' }}"
>
    <span class="mua-bookmark-toggle__icon" aria-hidden="true">
        @if ($bookmarked)
            ★
        @else
            ☆
        @endif
    </span>
    <span class="mua-bookmark-toggle__label">
        {{ $bookmarked ? $removeLabel : $addLabel }}
    </span>
</button>
