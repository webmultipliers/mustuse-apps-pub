@props(['block' => [], 'children' => [], 'gates' => []])

@php
    // `image` is normalized to { id, url, alt, width, height } by
    // BlockAttributeNormalizer::resolveImage(). Block-level alt/caption
    // override the attachment metadata.
    $image   = is_array($block['image'] ?? null) ? $block['image'] : null;
    $caption = $block['caption'] ?? '';
    $alt     = $block['alt']     ?? ($image['alt'] ?? '');
@endphp

@if (is_array($image) && !empty($image['url']))
    <figure class="mua-image">
        <img class="mua-image__img"
             src="{{ $image['url'] }}"
             alt="{{ $alt }}"
             @if (!empty($image['width']))  width="{{ (int) $image['width'] }}"   @endif
             @if (!empty($image['height'])) height="{{ (int) $image['height'] }}" @endif
             loading="lazy" />
        @if ($caption !== '')
            <figcaption class="mua-image__caption">{{ $caption }}</figcaption>
        @endif
    </figure>
@endif
