@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

{{--
    Post Template is an iteration marker. When dispatch-block hits a
    mustuse-apps-pub/post-template block, it should be inside a Query
    Loop whose QueryLoop Livewire component takes over iteration. This
    file only renders when a post-template accidentally ends up at the
    top level — we just render its children without looping so the
    content isn't lost.
--}}
@foreach ($children as $child)
    @include('livewire.partials.dispatch-block', ['block' => $child, 'context' => $context, 'gates' => $gates])
@endforeach
