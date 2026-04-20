@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $items         = \Illuminate\Support\Arr::get($data, 'items', []);
    $limit         = (int) ($block['limit'] ?? 20);
    $showThumbnail = $block['showThumbnail'] ?? true;

    if ($limit > 0 && is_array($items) && count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }
@endphp

@if (empty($items))
    <p class="mua-read-history-list__empty">
        {{ is_null($data) ? 'Loading…' : 'Nothing read yet. Articles you visit will show up here.' }}
    </p>
@else
    <ul class="mua-read-history-list">
        @foreach ($items as $row)
            @continue(! is_array($row))
            @php
                $post = is_array($row['post'] ?? null) ? $row['post'] : null;
                $visitedAt = (string) ($row['visited_at'] ?? '');
            @endphp
            @continue(! is_array($post))
            <li class="mua-read-history-list__item">
                <a href="{{ $post['url'] ?? '#' }}" wire:navigate class="mua-read-history-list__link">
                    @if ($showThumbnail && is_array($post['featured_image'] ?? null) && !empty($post['featured_image']['url']))
                        <img class="mua-read-history-list__thumb"
                             src="{{ $post['featured_image']['url'] }}"
                             alt="{{ $post['featured_image']['alt'] ?? '' }}"
                             loading="lazy" />
                    @endif
                    <div class="mua-read-history-list__body">
                        <h4 class="mua-read-history-list__title">{{ $post['title'] ?? '' }}</h4>
                        @if ($visitedAt !== '')
                            <time class="mua-read-history-list__visited" datetime="{{ $visitedAt }}">
                                {{ \Carbon\Carbon::parse($visitedAt)->diffForHumans() }}
                            </time>
                        @endif
                    </div>
                </a>
            </li>
        @endforeach
    </ul>
@endif
