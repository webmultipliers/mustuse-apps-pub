@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $heading    = $block['heading']    ?? '';
    $subheading = $block['subheading'] ?? '';
    $image      = $block['imageUrl']   ?? null;
    $cta        = $block['cta']        ?? null;
@endphp

<section class="mua-hero">
    @if (is_array($image) && !empty($image['url']))
        <img class="mua-hero__image"
             src="{{ $image['url'] }}"
             alt="{{ $image['alt'] ?? '' }}"
             @if(!empty($image['width'])) width="{{ $image['width'] }}" @endif
             @if(!empty($image['height'])) height="{{ $image['height'] }}" @endif />
    @endif

    <div class="mua-hero__body">
        @if ($heading !== '')
            <h1 class="mua-hero__heading">{{ $heading }}</h1>
        @endif

        @if ($subheading !== '')
            <p class="mua-hero__subheading">{{ $subheading }}</p>
        @endif

        @if (is_array($cta) && !empty($cta['label']))
            <a class="mua-hero__cta"
               href="{{ $cta['url'] ?: '#' }}"
               data-target-type="{{ $cta['type'] ?? 'url' }}"
               wire:navigate>
                {{ $cta['label'] }}
            </a>
        @endif
    </div>
</section>
