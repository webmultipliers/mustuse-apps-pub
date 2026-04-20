{{--
    Inlines three stacked style layers: the shell's base CSS, the
    publisher's branding variables from the manifest, and the per-block
    CSS collected by BlockAssetCollector for blocks actually present on
    this screen. `inlineBlockCss` / `inlineBlockJs` are set by
    NativeEdge::render() via layoutData(); default-empty for any other
    layout consumer.
--}}
@php
    $hasViteManifest = file_exists(public_path('build/manifest.json'))
        || file_exists(public_path('build/.vite/manifest.json'));
    $baseCssPath = resource_path('css/app.css');
    $baseCss     = (! $hasViteManifest && is_file($baseCssPath))
        ? (string) @file_get_contents($baseCssPath)
        : '';

    $branding     = data_get($manifest ?? [], 'branding', []);
    $brandingVars = array_filter([
        '--mua-color-primary'    => $branding['primary_color']    ?? null,
        '--mua-color-accent'     => $branding['accent_color']     ?? null,
        '--mua-color-background' => $branding['background_color'] ?? null,
    ]);

    $blockCss = $inlineBlockCss ?? '';
    $blockJs  = $inlineBlockJs  ?? '';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $branding['primary_color'] ?? '#1e1e1e' }}">
    <title>{{ $title ?? config('app.name', 'App') }}</title>

    @if ($hasViteManifest)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @elseif ($baseCss !== '')
        <style id="mua-shell-base">{!! $baseCss !!}</style>
    @endif

    @if (! empty($brandingVars))
        <style id="mua-branding-vars">:root {
            @foreach ($brandingVars as $prop => $value)
                {{ $prop }}: {{ $value }};
            @endforeach
        }</style>
    @endif

    @if ($blockCss !== '')
        <style id="mua-block-styles">{!! $blockCss !!}</style>
    @endif

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @if ($blockJs !== '')
        <script id="mua-block-scripts" type="module">{!! $blockJs !!}</script>
    @endif

    @livewireScripts
</body>
</html>
