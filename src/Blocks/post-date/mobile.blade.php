@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $iso    = (string) \Illuminate\Support\Arr::get($context, 'post.date_iso', '');
    $human  = (string) \Illuminate\Support\Arr::get($context, 'post.date_human', '');
    $format = (string) ($block['format'] ?? 'human');

    if ($format === 'absolute' && $iso !== '') {
        try {
            $display = \Carbon\Carbon::parse($iso)->format('M j, Y');
        } catch (\Throwable $e) {
            $display = $human;
        }
    } else {
        $display = $human;
    }
@endphp

@if ($display !== '')
    <time class="mua-post-date" @if ($iso !== '') datetime="{{ $iso }}" @endif>{{ $display }}</time>
@endif
