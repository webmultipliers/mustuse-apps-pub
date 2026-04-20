@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    // Prefer a term-level author context (on /author/{slug} screens);
    // otherwise fall back to the current post's author (on article detail).
    $author = \Illuminate\Support\Arr::get($context, 'author')
        ?: \Illuminate\Support\Arr::get($context, 'post.author');
    $showBio       = $block['showBio']       ?? true;
    $showPostCount = $block['showPostCount'] ?? false;
@endphp

@if (is_array($author))
    <a class="mua-author-card" href="{{ $author['url'] ?? '#' }}" wire:navigate>
        @if (is_array($author['avatar'] ?? null) && !empty($author['avatar']['url']))
            <img class="mua-author-card__avatar"
                 src="{{ $author['avatar']['url'] }}"
                 alt="{{ $author['avatar']['alt'] ?? $author['name'] ?? '' }}"
                 width="{{ $author['avatar']['width'] ?? 64 }}"
                 height="{{ $author['avatar']['height'] ?? 64 }}"
                 loading="lazy" />
        @endif
        <div>
            <h4 class="mua-author-card__name">{{ $author['name'] ?? '' }}</h4>
            @if ($showPostCount && isset($author['post_count']))
                <small>{{ $author['post_count'] }} articles</small>
            @endif
            @if ($showBio && !empty($author['bio']))
                <p class="mua-author-card__bio">{{ $author['bio'] }}</p>
            @endif
        </div>
    </a>
@endif
