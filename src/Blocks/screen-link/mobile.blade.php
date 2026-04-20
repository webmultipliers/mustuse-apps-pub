@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $label       = (string) ($block['label']       ?? '');
    $description = (string) ($block['description'] ?? '');
    $path        = (string) ($block['path']        ?? '/');
    $icon        = (string) ($block['icon']        ?? '');
@endphp

@if ($label !== '')
    <a class="mua-screen-link" href="{{ $path }}" wire:navigate>
        @if ($icon !== '')
            <span class="mua-screen-link__icon" aria-hidden="true" data-icon="{{ $icon }}"></span>
        @endif
        <span class="mua-screen-link__body">
            <span class="mua-screen-link__label">{{ $label }}</span>
            @if ($description !== '')
                <span class="mua-screen-link__description">{{ $description }}</span>
            @endif
        </span>
        <span class="mua-screen-link__chevron" aria-hidden="true">›</span>
    </a>
@endif
