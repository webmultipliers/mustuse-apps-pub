@props([
    'block'    => [],
    'children' => [],
    'gates'    => [],
    'context'  => null,
    'data'     => null,
])

@php
    $allTerms = \Illuminate\Support\Arr::get($context, 'post.terms', []);
    $taxonomy = (string) ($block['taxonomy'] ?? 'category');
    $max      = (int) ($block['max'] ?? 5);

    $terms = array_values(array_filter(
        is_array($allTerms) ? $allTerms : [],
        static function ($term) use ($taxonomy) {
            if (! is_array($term)) {
                return false;
            }
            if ($taxonomy === 'any') {
                return true;
            }
            return ($term['taxonomy'] ?? '') === $taxonomy;
        }
    ));
    if ($max > 0) {
        $terms = array_slice($terms, 0, $max);
    }
@endphp

@if (!empty($terms))
    <nav class="mua-post-terms" aria-label="Tags">
        @foreach ($terms as $term)
            <a class="mua-pill" href="{{ $term['url'] ?? '#' }}" wire:navigate>{{ $term['name'] ?? '' }}</a>
        @endforeach
    </nav>
@endif
