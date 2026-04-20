@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $heading     = (string) ($block['heading']     ?? '');
    $seeAllLabel = (string) ($block['seeAllLabel'] ?? '');
    $seeAllUrl   = (string) ($block['seeAllUrl']   ?? '');
    $children    = is_array($children) ? $children : [];
@endphp

<section class="mua-section">
    @if ($heading !== '' || ($seeAllLabel !== '' && $seeAllUrl !== ''))
        <header class="mua-section__header">
            @if ($heading !== '')
                <h2 class="mua-section__heading">{{ $heading }}</h2>
            @endif
            @if ($seeAllLabel !== '' && $seeAllUrl !== '')
                <a class="mua-section__link" href="{{ $seeAllUrl }}" wire:navigate>{{ $seeAllLabel }} →</a>
            @endif
        </header>
    @endif

    @foreach ($children as $child)
        @include('livewire.partials.dispatch-block', ['block' => $child])
    @endforeach
</section>
