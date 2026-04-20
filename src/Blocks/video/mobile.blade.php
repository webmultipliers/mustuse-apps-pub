@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $src     = $block['videoUrl'] ?? '';
    $poster  = is_array($block['posterImage'] ?? null) ? $block['posterImage'] : null;
    $caption = $block['caption']  ?? '';
@endphp

@if ($src !== '')
    <figure class="mua-video">
        <video class="mua-video__player"
               src="{{ $src }}"
               @if (is_array($poster) && !empty($poster['url'])) poster="{{ $poster['url'] }}" @endif
               controls
               playsinline
               preload="metadata">
            {{ __('Your device does not support HTML5 video.') }}
        </video>
        @if ($caption !== '')
            <figcaption class="mua-video__caption">{{ $caption }}</figcaption>
        @endif
    </figure>
@endif
