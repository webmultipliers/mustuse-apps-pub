@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

{{--
    This file exists to satisfy the per-block projection contract, but
    the actual rendering lives in `app/Livewire/QueryLoop.php` because
    the iteration needs a Livewire component wrapping the PubClient fetch.
    dispatch-block.blade.php routes mustuse-apps-pub/query-loop to
    <livewire:query-loop ...> and this file is never invoked at runtime.
--}}
<div class="mua-query-loop mua-query-loop--static-placeholder">
    @foreach ($children as $child)
        @include('livewire.partials.dispatch-block', ['block' => $child, 'context' => $context])
    @endforeach
</div>
