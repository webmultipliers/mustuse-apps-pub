@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $style = (string) ($block['style'] ?? 'bullet');
    $raw   = (string) ($block['items'] ?? '');
    $items = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw))));
    $tag   = $style === 'numbered' ? 'ol' : 'ul';
@endphp

@if (!empty($items))
    <{{ $tag }} class="mua-list mua-list--{{ $style }}">
        @foreach ($items as $item)
            <li>{{ $item }}</li>
        @endforeach
    </{{ $tag }}>
@endif
