{{--
    Dispatch decisions, by type prefix + native.json shape:
      mustuse-apps-pub/query-loop     → <livewire:query-loop>     (iterates per item)
      mustuse-apps-pub/post-template  → render children once      (surfaced outside a loop)
      mustuse-apps-pub/* + dataSource → <livewire:dynamic-block>  (single-block fetch)
      mustuse-apps-pub/*              → plain Blade component     ($context always passed)
      core/*                          → minimal HTML fallback     (heading/paragraph/etc.)
      anything else                   → silently skipped
--}}
@php
    $type            = $block['type'] ?? ($block['blockName'] ?? '');
    $blockAttributes = $block['attributes'] ?? [];
    $children        = $block['children'] ?? ($block['innerBlocks'] ?? []);
    $context         = $context ?? null;
    $gates           = $gates   ?? [];

    $shellState = $shellState ?? [];

    $isMustuse = str_starts_with($type, 'mustuse-apps-pub/');
    $isCore    = str_starts_with($type, 'core/');
    $slug      = $isMustuse ? substr($type, strlen('mustuse-apps-pub/')) : '';
    $coreName  = $isCore    ? substr($type, strlen('core/'))             : '';

    $nativePath = $slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)
        ? public_path('blocks/' . $slug . '/native.json')
        : null;
    $native     = $nativePath !== null && is_file($nativePath)
        ? json_decode((string) @file_get_contents($nativePath), true)
        : null;
    $dataSource = is_array($native) && isset($native['dataSource']) && is_array($native['dataSource'])
        ? $native['dataSource']
        : null;

    $componentAlias = $slug !== '' ? 'components.mustuse.' . $slug : null;
@endphp

@if ($slug === 'query-loop')
    <livewire:query-loop
        :attributes="$blockAttributes"
        :children="$children"
        :context="$context"
        wire:key="loop-{{ md5(serialize($blockAttributes) . count($children)) }}" />

@elseif ($slug === 'post-template')
    {{-- Surfaced outside a QueryLoop — render children once so content isn't invisible. --}}
    @foreach ($children as $inner)
        @include('livewire.partials.dispatch-block', [
            'block'      => $inner,
            'context'    => $context,
            'gates'      => $gates,
            'shellState' => $shellState,
        ])
    @endforeach

@elseif ($dataSource)
    <livewire:dynamic-block
        :slug="$slug"
        :attributes="$blockAttributes"
        :context="$context"
        wire:key="dyn-{{ $slug }}-{{ md5(serialize($blockAttributes)) }}" />

@elseif ($componentAlias && View::exists($componentAlias))
    @component($componentAlias, [
        'block'      => $blockAttributes,
        'children'   => $children,
        'gates'      => $gates,
        'context'    => $context,
        'shellState' => $shellState,
    ])
    @endcomponent

@elseif ($isCore)
    @include('livewire.partials.core-block', [
        'name'       => $coreName,
        'attributes' => $blockAttributes,
        'children'   => $children,
        'context'    => $context,
        'gates'      => $gates,
    ])
@endif
