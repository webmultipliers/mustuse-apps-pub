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
    $columns       = $block['columns']       ?? 'auto';
@endphp

@if (!empty($items))
    <div
        class="mua-article-grid"
        data-columns="{{ $columns }}"
        @if ($columns !== 'auto') style="grid-template-columns: repeat({{ (int) $columns }}, 1fr);" @endif
    >
        @foreach ($items as $article)
            @continue(! is_array($article))
            <article class="mua-article-grid__item">
                <a href="/article/{{ $article['slug'] ?? $article['id'] ?? '' }}" wire:navigate>
                    @if ($showThumbnail && is_array($article['featured_image'] ?? null) && !empty($article['featured_image']['url']))
                        <img class="mua-article-grid__thumb"
                             src="{{ $article['featured_image']['url'] }}"
                             alt="{{ $article['featured_image']['alt'] ?? '' }}"
                             loading="lazy" />
                    @endif
                    <h4 class="mua-article-grid__title">{{ $article['title'] ?? '' }}</h4>
                    @if ($showExcerpt && !empty($article['excerpt']))
                        <p class="mua-article-grid__excerpt">{{ $article['excerpt'] }}</p>
                    @endif
                </a>
            </article>
        @endforeach
    </div>
@else
    <div class="mua-article-grid mua-article-grid--empty" aria-busy="true"></div>
@endif
