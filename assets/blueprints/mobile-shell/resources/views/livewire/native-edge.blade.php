{{--
    Top-bar shows its hamburger only when a side-nav exists; otherwise
    the user taps it and gets an empty drawer they can't close. iOS
    hides the top-bar title by convention, so we only populate it on
    Android.
--}}
<div>
    @php
        $isAndroid    = \Native\Mobile\Facades\System::isAndroid();
        $displayTitle = $isAndroid ? ($title ?: 'App') : '';
        $sideEntries  = ! empty($sideNav) ? $sideNav : $drawerScreens;
        $hasSideMenu  = ! empty($sideEntries);
    @endphp

    <native:top-bar
        title="{{ $displayTitle }}"
        show-navigation-icon="{{ $hasSideMenu ? 'true' : 'false' }}"
    >
    </native:top-bar>

    @if ($hasSideMenu)
        <native:side-nav :gestures_enabled="true">
            <native:side-nav-header
                title="{{ $title ?: 'App' }}"
                subtitle="{{ $manifest['branding']['tagline'] ?? '' }}"
                :show-close-button="true"
                pinned
            />
            @foreach ($sideEntries as $entry)
                @php
                    $entryPath = rtrim((string) ($entry['path'] ?? '/'), '/') ?: '/';
                    $active    = $entryPath === rtrim($path, '/');
                @endphp
                <native:side-nav-item
                    id="{{ $entry['screen_id'] ?? $entry['id'] ?? $entryPath }}"
                    icon="{{ $entry['icon'] ?? 'document' }}"
                    label="{{ $entry['title'] ?? $entry['label'] ?? '' }}"
                    url="{{ $entry['path'] ?? '/' }}"
                    :active="$active"
                />
            @endforeach
        </native:side-nav>
    @endif

    @if ($screen)
        <main class="mua-screen" data-screen-id="{{ $screen['id'] ?? '' }}">
            @foreach (($screen['block_tree'] ?? []) as $block)
                @include('livewire.partials.dispatch-block', [
                    'block'      => $block,
                    'context'    => $this->context,
                    'gates'      => $this->gates,
                    'shellState' => [
                        'deviceInfo'      => $this->deviceInfo,
                        'networkStatus'   => $this->networkStatus,
                        'lastCallback'    => $this->lastCallback,
                        'subscriberState' => is_array($manifest['subscriber_state'] ?? null) ? $manifest['subscriber_state'] : [],
                    ],
                ])
            @endforeach
        </main>
    @else
        <main class="mua-screen mua-screen--empty">
            <p class="mua-screen__message">{{ $message }}</p>
        </main>
    @endif

    @if (!empty($navTabs))
        <native:bottom-nav label-visibility="labeled">
            @foreach ($navTabs as $tab)
                <native:bottom-nav-item
                    id="{{ $tab['screen_id'] ?? $tab['id'] ?? $tab['path'] ?? $loop->index }}"
                    icon="{{ $tab['icon'] ?? 'home' }}"
                    label="{{ $tab['title'] ?? $tab['label'] ?? '' }}"
                    url="{{ $tab['path'] ?? '/' }}"
                    :active="rtrim(($tab['path'] ?? '/'), '/') === rtrim($path, '/')"
                />
            @endforeach
        </native:bottom-nav>
    @endif
</div>
