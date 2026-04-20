@props(['block' => [], 'children' => [], 'gates' => [], 'context' => null, 'data' => null])

@php
    $heading   = (string) ($block['heading']   ?? 'Comments');
    $emptyText = (string) ($block['emptyText'] ?? 'Be the first to comment.');
    $enabled   = (bool)   ($data['enabled']    ?? false);
    $open      = (bool)   ($data['open']       ?? false);
    $items     = is_array($data['items'] ?? null) ? $data['items'] : [];
    $total     = (int)    ($data['total']      ?? 0);

    $renderItem = function (array $item, int $depth = 0) use (&$renderItem): string {
        $cls = 'mua-post-comments__item' . ($depth > 0 ? ' mua-post-comments__item--reply' : '');
        $html  = '<li class="' . $cls . '">';
        $html .= '  <div class="mua-post-comments__meta">';
        if (! empty($item['avatar'])) {
            $html .= '<img class="mua-post-comments__avatar" src="' . e($item['avatar']) . '" alt="">';
        }
        $html .= '    <strong class="mua-post-comments__author">' . e($item['author'] ?? '') . '</strong>';
        $html .= '    <time class="mua-post-comments__date">' . e($item['date'] ?? '') . '</time>';
        $html .= '  </div>';
        $html .= '  <div class="mua-post-comments__content">' . ($item['content'] ?? '') . '</div>';
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        if ($children !== []) {
            $html .= '<ul class="mua-post-comments__children">';
            foreach ($children as $c) {
                $html .= $renderItem($c, $depth + 1);
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
        return $html;
    };
@endphp

@if (! $enabled)
    {{-- Publisher disabled comments on the App — render nothing. --}}
@else
    <section class="mua-post-comments" aria-label="{{ $heading }}">
        <h3 class="mua-post-comments__heading">
            {{ $heading }}
            @if ($total > 0)
                <span class="mua-post-comments__count">({{ $total }})</span>
            @endif
        </h3>

        @if (! $open)
            <p class="mua-post-comments__empty">{{ __('Comments are closed for this post.') }}</p>
        @elseif ($items === [])
            <p class="mua-post-comments__empty">{{ $emptyText }}</p>
        @else
            <ul class="mua-post-comments__list">
                @foreach ($items as $item)
                    {!! $renderItem((array) $item) !!}
                @endforeach
            </ul>
        @endif
    </section>
@endif
