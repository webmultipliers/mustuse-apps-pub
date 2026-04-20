@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $name         = (string) \Illuminate\Support\Arr::get($context, 'post.author.name', '');
    $url          = (string) \Illuminate\Support\Arr::get($context, 'post.author.url', '');
    $showPrefix   = $block['showPrefix']   ?? true;
    $linkToAuthor = ($block['linkToAuthor'] ?? true) && $url !== '';
@endphp

@if ($name !== '')
    <span class="mua-post-author-name">
        @if ($showPrefix)<span class="mua-post-author-name__prefix">By</span>@endif
        @if ($linkToAuthor)
            <a href="{{ $url }}" wire:navigate>{{ $name }}</a>
        @else
            {{ $name }}
        @endif
    </span>
@endif
