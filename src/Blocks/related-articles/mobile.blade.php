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
    $showExcerpt   = $block['showExcerpt']   ?? false;
@endphp

@if (!empty($items))
    <section class="mua-related-articles">
        <h4 class="mua-related-articles__heading">More like this</h4>
        <ul class="mua-related-articles__items">
            @foreach ($items as $article)
                @continue(! is_array($article))
                <li class="mua-related-articles__item">
                    <a class="mua-related-articles__link"
                       href="/article/{{ $article['slug'] ?? $article['id'] ?? '' }}"
                       wire:navigate>
                        @if ($showThumbnail && is_array($article['featured_image'] ?? null) && !empty($article['featured_image']['url']))
                            <img class="mua-related-articles__thumb"
                                 src="{{ $article['featured_image']['url'] }}"
                                 alt="{{ $article['featured_image']['alt'] ?? '' }}"
                                 loading="lazy" />
                        @endif
                        <span class="mua-related-articles__title">{{ $article['title'] ?? '' }}</span>
                        @if ($showExcerpt && !empty($article['excerpt']))
                            <p class="mua-related-articles__excerpt">{{ $article['excerpt'] }}</p>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
