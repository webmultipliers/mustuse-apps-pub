@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $postId   = (int) \Illuminate\Support\Arr::get($context, 'post.id', 0);
    $postType = (string) \Illuminate\Support\Arr::get($context, 'post.post_type', 'post');
@endphp

@if ($postId > 0)
    <livewire:bookmark-toggle
        :post-id="$postId"
        :post-type="$postType"
        :add-label="$block['addLabel']    ?? 'Bookmark'"
        :remove-label="$block['removeLabel'] ?? 'Bookmarked'"
        wire:key="bookmark-{{ $postId }}" />
@endif
