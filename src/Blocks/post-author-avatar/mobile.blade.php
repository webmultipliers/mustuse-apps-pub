@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $avatar       = \Illuminate\Support\Arr::get($context, 'post.author.avatar');
    $url          = (string) \Illuminate\Support\Arr::get($context, 'post.author.url', '');
    $name         = (string) \Illuminate\Support\Arr::get($context, 'post.author.name', '');
    $size         = (string) ($block['size'] ?? 'md');
    if (! in_array($size, ['sm', 'md', 'lg'], true)) {
        $size = 'md';
    }
    $linkToAuthor = ($block['linkToAuthor'] ?? true) && $url !== '';
@endphp

@if (is_array($avatar) && !empty($avatar['url']))
    @if ($linkToAuthor)
        <a class="mua-post-author-avatar mua-post-author-avatar--{{ $size }}" href="{{ $url }}" wire:navigate>
            <img src="{{ $avatar['url'] }}" alt="{{ $avatar['alt'] ?? $name }}" loading="lazy" />
        </a>
    @else
        <span class="mua-post-author-avatar mua-post-author-avatar--{{ $size }}">
            <img src="{{ $avatar['url'] }}" alt="{{ $avatar['alt'] ?? $name }}" loading="lazy" />
        </span>
    @endif
@endif
