@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    // Rendered via <livewire:search-box> so the input keeps state across
    // re-renders and the submit wires wire:navigate to /search?q=...
    //
    // If the block sits on the search-results screen, seed the input
    // with the current query so the user sees what they just searched.
    $seed = (string) \Illuminate\Support\Arr::get($context, 'search.query', '');
@endphp

<livewire:search-box
    :placeholder="$block['placeholder']  ?? 'Search articles…'"
    :submit-label="$block['submitLabel'] ?? 'Search'"
    :auto-focus="(bool) ($block['autoFocus'] ?? false)"
    :seed="$seed"
    wire:key="search-{{ md5(serialize($block)) }}" />
