@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    // $data is populated by the wrapping DynamicBlock Livewire component
    // (see `dispatch-block.blade.php` → `<livewire:dynamic-block>` when
    // the block's native.json carries a `dataSource`). The template is
    // defensive: renders a placeholder if the fetch is still pending or
    // the response is malformed.
    $items         = \Illuminate\Support\Arr::get($data, 'items', []);
    $showThumbnail = $block['showThumbnail'] ?? true;
    $showExcerpt   = $block['showExcerpt']   ?? true;
@endphp

<section class="mua-article-list">
    @if (empty($items))
        <p class="mua-article-list__empty">No articles to show yet.</p>
    @else
        <ul class="mua-article-list__items">
            @foreach ($items as $article)
                @continue(! is_array($article))
                <li class="mua-article-list__item">
                    <a class="mua-article-list__link"
                       href="/article/{{ $article['slug'] ?? $article['id'] ?? '' }}"
                       wire:navigate>
                        @if ($showThumbnail && is_array($article['featured_image'] ?? null) && !empty($article['featured_image']['url']))
                            <img class="mua-article-list__thumb"
                                 src="{{ $article['featured_image']['url'] }}"
                                 alt="{{ $article['featured_image']['alt'] ?? '' }}"
                                 loading="lazy" />
                        @endif
                        <div class="mua-article-list__body">
                            <h3 class="mua-article-list__item-title">{{ $article['title'] ?? '' }}</h3>
                            @if (!empty($article['date']))
                                <time class="mua-article-list__date" datetime="{{ $article['date'] }}">
                                    {{ \Carbon\Carbon::parse($article['date'])->diffForHumans() }}
                                </time>
                            @endif
                            @if ($showExcerpt && !empty($article['excerpt']))
                                <p class="mua-article-list__excerpt">{{ $article['excerpt'] }}</p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
