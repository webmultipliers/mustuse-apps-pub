@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $text  = $block['text']  ?? '';
    $level = $block['level'] ?? 'h2';
    if (! in_array($level, ['h2', 'h3', 'h4'], true)) {
        $level = 'h2';
    }
@endphp

@if ($text !== '')
    <{{ $level }} class="mua-section-heading mua-section-heading--{{ $level }}">{{ $text }}</{{ $level }}>
@endif
