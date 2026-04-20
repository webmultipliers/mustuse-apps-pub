@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    // article-card's /content?id=postId lookup puts the article in items[0].
    $article = \Illuminate\Support\Arr::get($data, 'items.0');
@endphp

@if (is_array($article))
    <article class="mua-article-card">
        <a class="mua-article-card__link"
           href="/article/{{ $article['slug'] ?? $article['id'] ?? '' }}"
           wire:navigate>
            @if (($block['showThumbnail'] ?? true) && is_array($article['featured_image'] ?? null) && !empty($article['featured_image']['url']))
                <img class="mua-article-card__image"
                     src="{{ $article['featured_image']['url'] }}"
                     alt="{{ $article['featured_image']['alt'] ?? '' }}"
                     loading="lazy" />
            @endif
            <div class="mua-article-card__body">
                <h3 class="mua-article-card__title">{{ $article['title'] ?? '' }}</h3>
                @if (($block['showExcerpt'] ?? true) && !empty($article['excerpt']))
                    <p class="mua-article-card__excerpt">{{ $article['excerpt'] }}</p>
                @endif
            </div>
        </a>
    </article>
@else
    <article class="mua-article-card mua-article-card--placeholder" aria-busy="true"></article>
@endif
