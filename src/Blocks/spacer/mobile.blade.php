@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $size = (string) ($block['size'] ?? 'md');
    if (! in_array($size, ['sm', 'md', 'lg', 'xl'], true)) {
        $size = 'md';
    }
@endphp

<div class="mua-spacer mua-spacer--{{ $size }}" aria-hidden="true"></div>
