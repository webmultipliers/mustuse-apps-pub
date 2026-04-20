<div
    class="mua-dynamic-block mua-dynamic-block--{{ $slug }}"
    data-stale="{{ $stale ? 'true' : 'false' }}"
    data-loading="{{ $loading ? 'true' : 'false' }}"
    wire:key="dynamic-{{ $slug }}-{{ md5(serialize($attributes)) }}">
    @php
        $componentAlias = 'components.mustuse.' . $slug;
    @endphp

    @if (View::exists($componentAlias))
        @component($componentAlias, [
            'block'    => $attributes,
            'children' => [],
            'gates'    => [],
            'context'  => $context,
            'data'     => $data,
        ])
        @endcomponent
    @else
        <p class="mua-dynamic-block__missing">Missing renderer for <code>{{ $slug }}</code>.</p>
    @endif
</div>
