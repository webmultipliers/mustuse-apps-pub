@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $minutes = (int) \Illuminate\Support\Arr::get($context, 'post.reading_time_min', 0);
    $format  = (string) ($block['format'] ?? 'short');
    $display = $minutes > 0
        ? ($format === 'long' ? $minutes . '-minute read' : $minutes . ' min read')
        : '';
@endphp

@if ($display !== '')
    <span class="mua-post-reading-time">{{ $display }}</span>
@endif
