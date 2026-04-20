@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $excerpt = (string) \Illuminate\Support\Arr::get($context, 'post.excerpt', '');
@endphp

@if ($excerpt !== '')
    <p class="mua-post-excerpt">{{ $excerpt }}</p>
@endif
