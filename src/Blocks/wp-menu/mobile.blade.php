@props(['block' => [], 'children' => [], 'gates' => [], 'context' => null, 'data' => null])

@php
    $items   = is_array($data['items'] ?? null) ? $data['items'] : [];
    $style   = (string) ($block['style'] ?? 'list');
    $classes = 'mua-wp-menu mua-wp-menu--' . ($style === 'chips' ? 'chips' : 'list');
@endphp

@if ($items !== [])
    <nav class="{{ $classes }}" role="navigation">
        <ul class="mua-wp-menu__items">
            @foreach ($items as $item)
                @php
                    $title    = (string) ($item['title'] ?? '');
                    $url      = (string) ($item['url']   ?? '');
                    $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                @endphp
                <li class="mua-wp-menu__item">
                    <a href="{{ $url }}" wire:navigate>{{ $title }}</a>
                    @if ($children !== [])
                        <ul class="mua-wp-menu__children">
                            @foreach ($children as $child)
                                <li class="mua-wp-menu__item mua-wp-menu__item--child">
                                    <a href="{{ $child['url'] ?? '' }}" wire:navigate>{{ $child['title'] ?? '' }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
@endif
