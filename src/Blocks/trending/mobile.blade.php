@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $items         = \Illuminate\Support\Arr::get($data, 'items', []);
    $showThumbnail = $block['showThumbnail'] ?? true;
@endphp

@if (!empty($items))
    <section class="mua-trending">
        <ol class="mua-trending__items">
            @foreach ($items as $index => $article)
                @continue(! is_array($article))
                <li class="mua-trending__item">
                    <span class="mua-trending__rank">{{ $index + 1 }}</span>
                    <a class="mua-trending__link"
                       href="/article/{{ $article['slug'] ?? $article['id'] ?? '' }}"
                       wire:navigate>
                        @if ($showThumbnail && is_array($article['featured_image'] ?? null) && !empty($article['featured_image']['url']))
                            <img class="mua-trending__thumb"
                                 src="{{ $article['featured_image']['url'] }}"
                                 alt="{{ $article['featured_image']['alt'] ?? '' }}"
                                 loading="lazy" />
                        @endif
                        <span class="mua-trending__title">{{ $article['title'] ?? '' }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endif
