@php
    $parts = $this->splitChildren();
@endphp

<div
    class="mua-query-loop mua-query-loop--{{ $layout }}"
    data-stale="{{ $stale ? 'true' : 'false' }}"
    wire:key="query-loop-{{ md5(serialize($attributes)) }}"
>
    @foreach ($parts['chromeBefore'] as $child)
        @include('livewire.partials.dispatch-block', [
            'block'   => $child,
            'context' => $context,
            'gates'   => [],
        ])
    @endforeach

    @if ($loading)
        <div class="mua-query-loop__loading" aria-busy="true">Loading…</div>
    @elseif (empty($items))
        <p class="mua-query-loop__empty">No posts to show.</p>
    @else
        @php
            $templateBlock = $parts['postTemplate'];
            $innerBlocks   = is_array($templateBlock['children'] ?? null)
                ? $templateBlock['children']
                : (is_array($templateBlock['innerBlocks'] ?? null) ? $templateBlock['innerBlocks'] : []);
        @endphp

        @foreach ($items as $item)
            <article class="mua-query-loop__item">
                @php $itemContext = $this->contextForItem($item); @endphp
                @foreach ($innerBlocks as $inner)
                    @include('livewire.partials.dispatch-block', [
                        'block'   => $inner,
                        'context' => $itemContext,
                        'gates'   => [],
                    ])
                @endforeach
            </article>
        @endforeach
    @endif

    @foreach ($parts['chromeAfter'] as $child)
        @include('livewire.partials.dispatch-block', [
            'block'   => $child,
            'context' => $context,
            'gates'   => [],
        ])
    @endforeach
</div>
