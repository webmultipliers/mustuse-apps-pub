@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $metaKey = (string) ($block['metaKey'] ?? '');
    $label   = (string) ($block['label']   ?? '');
    $value   = \Illuminate\Support\Arr::get($context, 'post.meta.' . $metaKey);
@endphp

@if ($metaKey !== '' && $value !== null && $value !== '')
    <div class="mua-post-meta">
        @if ($label !== '')
            <span class="mua-post-meta__label">{{ $label }}:</span>
        @endif
        <span class="mua-post-meta__value">
            @if (is_array($value))
                {{ implode(', ', array_map('strval', $value)) }}
            @else
                {{ (string) $value }}
            @endif
        </span>
    </div>
@endif
