@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $content = (string) ($block['content'] ?? '');
    $align   = (string) ($block['align']   ?? 'left');
    if (! in_array($align, ['left', 'center', 'right'], true)) {
        $align = 'left';
    }
@endphp

{{-- The pub sanitizes content before it ever reaches the manifest;
     the raw HTML is published block output from WordPress core. --}}
<div class="mua-text mua-text--{{ $align }}">
    {!! $content !!}
</div>
