@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $items         = \Illuminate\Support\Arr::get($data, 'items', []);
    $total         = \Illuminate\Support\Arr::get($data, 'pagination.total', null);
    $query         = (string) \Illuminate\Support\Arr::get($context, 'search.query', '');
    $showThumbnail = $block['showThumbnail'] ?? true;
    $showExcerpt   = $block['showExcerpt']   ?? true;
@endphp

<section class="mua-search-results">
    @if ($query !== '')
        <h3 class="mua-search-results__heading">
            @if ($total !== null)
                {{ $total }} result{{ (int) $total === 1 ? '' : 's' }} for
            @endif
            <em>“{{ $query }}”</em>
        </h3>
    @endif

    @if (empty($items))
        <p class="mua-search-results__empty">
            @if ($query === '')
                Type in the search box above to find articles.
            @else
                No articles match that search.
            @endif
        </p>
    @else
        <ul class="mua-search-results__items">
            @foreach ($items as $article)
                @continue(! is_array($article))
                <li class="mua-search-results__item">
                    <a class="mua-search-results__link"
                       href="/article/{{ $article['slug'] ?? $article['id'] ?? '' }}"
                       wire:navigate>
                        @if ($showThumbnail && is_array($article['featured_image'] ?? null) && !empty($article['featured_image']['url']))
                            <img class="mua-search-results__thumb"
                                 src="{{ $article['featured_image']['url'] }}"
                                 alt="{{ $article['featured_image']['alt'] ?? '' }}"
                                 loading="lazy" />
                        @endif
                        <div class="mua-search-results__body">
                            <h4 class="mua-search-results__title">{{ $article['title'] ?? '' }}</h4>
                            @if ($showExcerpt && !empty($article['excerpt']))
                                <p class="mua-search-results__excerpt">{{ $article['excerpt'] }}</p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
