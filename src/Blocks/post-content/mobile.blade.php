@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    // `content` is already run through WP's `the_content` filters on the
    // pub side (ContextBuilder::postContext). Publishers are responsible
    // for sanitizing via their usual KSES filters.
    $content = (string) \Illuminate\Support\Arr::get($context, 'post.content', '');
@endphp

@if ($content !== '')
    <div class="mua-post-content">
        {!! $content !!}
    </div>
@endif
