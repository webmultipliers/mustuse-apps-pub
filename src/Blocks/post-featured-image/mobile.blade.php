@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $image       = \Illuminate\Support\Arr::get($context, 'post.featured_image');
    $aspectRatio = (string) ($block['aspectRatio'] ?? '16:9');
    if (! in_array($aspectRatio, ['16:9', '4:3', '1:1', 'natural'], true)) {
        $aspectRatio = '16:9';
    }
    $linkToPost = ($block['linkToPost'] ?? false) && \Illuminate\Support\Arr::get($context, 'post.url');
    $postUrl    = (string) \Illuminate\Support\Arr::get($context, 'post.url', '');
@endphp

@if (is_array($image) && !empty($image['url']))
    <figure class="mua-post-featured-image mua-post-featured-image--{{ str_replace(':', 'x', $aspectRatio) }}">
        @if ($linkToPost)
            <a href="{{ $postUrl }}" wire:navigate>
                <img src="{{ $image['url'] }}"
                     alt="{{ $image['alt'] ?? '' }}"
                     @if (!empty($image['width']))  width="{{ (int) $image['width'] }}"   @endif
                     @if (!empty($image['height'])) height="{{ (int) $image['height'] }}" @endif
                     loading="lazy" />
            </a>
        @else
            <img src="{{ $image['url'] }}"
                 alt="{{ $image['alt'] ?? '' }}"
                 @if (!empty($image['width']))  width="{{ (int) $image['width'] }}"   @endif
                 @if (!empty($image['height'])) height="{{ (int) $image['height'] }}" @endif
                 loading="lazy" />
        @endif
    </figure>
@endif
