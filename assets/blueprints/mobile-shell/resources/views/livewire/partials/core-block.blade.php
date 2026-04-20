{{--
    Minimal renderer for WordPress `core/*` blocks that appear in a
    manifest's block_tree. The manifest is HMAC-verified before it
    reaches this partial, so `attributes.content` is trusted enough
    to emit unescaped — otherwise inline formatting (links, bold,
    italics) serialised from Gutenberg would show as literal tags.

    Any block not listed here is skipped without error so older
    shells survive new core blocks shipped in future WP releases.

    Expected vars:
      $name       — block name without the `core/` prefix
      $attributes — decoded `attrs` object from the manifest
      $children   — innerBlocks for container blocks
      $context    — screen-level route context (passed through recursion)
      $gates      — biometric auth-gate state (passed through recursion)
--}}
@php
    $attrs   = is_array($attributes ?? null) ? $attributes : [];
    $kids    = is_array($children   ?? null) ? $children   : [];
    $content = (string) ($attrs['content'] ?? '');
    $align   = (string) ($attrs['textAlign'] ?? $attrs['align'] ?? '');
    $alignClass = $align !== '' ? ' has-text-align-' . preg_replace('/[^a-z]/', '', strtolower($align)) : '';
@endphp

@switch($name)
    @case('heading')
        @php
            $level = (int) ($attrs['level'] ?? 2);
            $level = max(1, min(6, $level));
            $tag   = 'h' . $level;
        @endphp
        @if ($content !== '')
            <{{ $tag }} class="mua-core-heading{{ $alignClass }}">{!! $content !!}</{{ $tag }}>
        @endif
        @break

    @case('paragraph')
        @php
            $dropCap = ! empty($attrs['dropCap']) ? ' has-drop-cap' : '';
        @endphp
        @if ($content !== '')
            <p class="mua-core-paragraph{{ $alignClass }}{{ $dropCap }}">{!! $content !!}</p>
        @endif
        @break

    @case('list')
        @php
            $ordered = ! empty($attrs['ordered']);
            $tag     = $ordered ? 'ol' : 'ul';
            $values  = (string) ($attrs['values'] ?? '');
        @endphp
        <{{ $tag }} class="mua-core-list">
            @if ($values !== '')
                {{-- Gutenberg v1 serialises list items into a single `values` HTML blob. --}}
                {!! $values !!}
            @else
                @foreach ($kids as $child)
                    @include('livewire.partials.dispatch-block', [
                        'block'   => $child,
                        'context' => $context,
                        'gates'   => $gates,
                    ])
                @endforeach
            @endif
        </{{ $tag }}>
        @break

    @case('list-item')
        @if ($content !== '')
            <li class="mua-core-list-item">{!! $content !!}</li>
        @endif
        @break

    @case('image')
        @php
            $url     = (string) ($attrs['url']     ?? '');
            $alt     = (string) ($attrs['alt']     ?? '');
            $caption = (string) ($attrs['caption'] ?? '');
            $href    = (string) ($attrs['href']    ?? '');
        @endphp
        @if ($url !== '')
            <figure class="mua-core-image">
                @if ($href !== '')
                    <a href="{{ $href }}"><img src="{{ $url }}" alt="{{ $alt }}" loading="lazy" /></a>
                @else
                    <img src="{{ $url }}" alt="{{ $alt }}" loading="lazy" />
                @endif
                @if ($caption !== '')
                    <figcaption>{!! $caption !!}</figcaption>
                @endif
            </figure>
        @endif
        @break

    @case('quote')
        @php
            $value    = (string) ($attrs['value']    ?? '');
            $citation = (string) ($attrs['citation'] ?? '');
        @endphp
        <blockquote class="mua-core-quote">
            @if ($value !== '')
                {!! $value !!}
            @endif
            @foreach ($kids as $child)
                @include('livewire.partials.dispatch-block', [
                    'block'   => $child,
                    'context' => $context,
                    'gates'   => $gates,
                ])
            @endforeach
            @if ($citation !== '')
                <cite>{!! $citation !!}</cite>
            @endif
        </blockquote>
        @break

    @case('separator')
        <hr class="mua-core-separator" />
        @break

    @case('spacer')
        @php
            $height = (string) ($attrs['height'] ?? '32px');
            if (ctype_digit($height)) { $height .= 'px'; }
        @endphp
        <div class="mua-core-spacer" style="height: {{ $height }};" aria-hidden="true"></div>
        @break

    @case('html')
        @if ($content !== '')
            {!! $content !!}
        @endif
        @break

    @case('group')
    @case('columns')
    @case('column')
        <div class="mua-core-{{ $name }}">
            @foreach ($kids as $child)
                @include('livewire.partials.dispatch-block', [
                    'block'   => $child,
                    'context' => $context,
                    'gates'   => $gates,
                ])
            @endforeach
        </div>
        @break
@endswitch
