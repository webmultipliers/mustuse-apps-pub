@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $title      = (string) \Illuminate\Support\Arr::get($context, 'post.title', '');
    $postUrl    = (string) \Illuminate\Support\Arr::get($context, 'post.url', '');
    $level      = (string) ($block['level'] ?? 'h1');
    if (! in_array($level, ['h1', 'h2', 'h3'], true)) {
        $level = 'h1';
    }
    $linkToPost = ($block['linkToPost'] ?? false) && $postUrl !== '';
@endphp

@if ($title !== '')
    <{{ $level }} class="mua-post-title mua-post-title--{{ $level }}">
        @if ($linkToPost)
            <a href="{{ $postUrl }}" wire:navigate>{{ $title }}</a>
        @else
            {{ $title }}
        @endif
    </{{ $level }}>
@endif
