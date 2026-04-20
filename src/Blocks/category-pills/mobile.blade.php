@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $items      = \Illuminate\Support\Arr::get($data, 'items', []);
    $activeSlug = (string) ($block['activeSlug'] ?? '');
@endphp

@if (!empty($items))
    <nav class="mua-category-pills" aria-label="Categories">
        @foreach ($items as $item)
            @continue(! is_array($item))
            @php $isActive = $activeSlug !== '' && $activeSlug === ($item['slug'] ?? ''); @endphp
            <div class="mua-category-pills__item {{ $isActive ? 'is-active' : '' }}">
                <a class="mua-pill"
                   href="{{ $item['url'] ?? ('/' . ($item['taxonomy'] ?? 'category') . '/' . ($item['slug'] ?? '')) }}"
                   wire:navigate>
                    {{ $item['name'] ?? '' }}
                </a>
            </div>
        @endforeach
    </nav>
@else
    <nav class="mua-category-pills mua-category-pills--empty" aria-busy="true" aria-label="Categories"></nav>
@endif
