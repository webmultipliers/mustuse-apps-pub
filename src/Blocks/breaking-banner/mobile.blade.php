@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $label    = (string) ($block['label']    ?? 'Breaking');
    $headline = (string) ($block['headline'] ?? '');
    $linkUrl  = (string) ($block['linkUrl']  ?? '');
@endphp

@if ($headline !== '')
    @if ($linkUrl !== '')
        <a class="mua-breaking-banner" href="{{ $linkUrl }}" wire:navigate role="alert">
            <span class="mua-breaking-banner__label">{{ $label }}</span>
            <span class="mua-breaking-banner__headline">{{ $headline }}</span>
        </a>
    @else
        <div class="mua-breaking-banner" role="alert">
            <span class="mua-breaking-banner__label">{{ $label }}</span>
            <span class="mua-breaking-banner__headline">{{ $headline }}</span>
        </div>
    @endif
@endif
