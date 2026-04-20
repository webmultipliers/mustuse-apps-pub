@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $text = (string) ($block['text'] ?? '');
    $cite = (string) ($block['cite'] ?? '');
@endphp

@if ($text !== '')
    <blockquote class="mua-quote">
        <p class="mua-quote__text">“{{ $text }}”</p>
        @if ($cite !== '')
            <cite class="mua-quote__cite">— {{ $cite }}</cite>
        @endif
    </blockquote>
@endif
