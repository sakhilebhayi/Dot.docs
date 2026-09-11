@php
    /*
     * "Fair Copy" — the application shell.
     *
     * DAY is the default now: a writing tool opens on paper, not on an
     * instrument panel. The `theme` cookie is read HERE, server-side, so a
     * night-mode reload never flashes day; it is excluded from Laravel's cookie
     * encryption in bootstrap/app.php, because resources/js/shell.js writes it
     * from the browser. A reader who has never touched the switch and whose OS
     * asks for dark gets night from the `prefers-color-scheme` guard in
     * shell.css — which is why an explicit day choice is stamped as
     * <html class="light"> rather than as no class at all.
     *
     * Nothing loads from a CDN: Tailwind and Alpine both come from the Vite
     * bundle (Alpine only ever through Livewire's own copy — a second Alpine
     * wins the window.Alpine slot and kills every wire: binding on the page).
     *
     * DOM order inside .shell is rail -> canvas -> dock -> top bar, and the
     * grid puts the bar back on top. That is deliberate: Tab has to run
     * skip link -> rail -> paper -> dock before it reaches the theme toggle.
     */
    $cookieTheme = request()->cookie('theme');
    $theme = $cookieTheme === 'dark' ? 'dark' : ($cookieTheme === 'light' ? 'light' : 'system');
    $shellDocument = app(\App\Support\ShellContext::class)->document();
    $pageTitle = trim((string) ($title ?? ''));

    // The editor is the one route with a canvas competing for width, so it is
    // the one route where both panels start collapsed (spec §2.4). Everywhere
    // else the rail is the page's navigation and stays open.
    $isEditor = request()->routeIs('documents.edit');
    $railState = $isEditor ? 'collapsed' : 'expanded';
    $dockState = $isEditor ? 'collapsed' : 'expanded';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $theme === 'system' ? '' : $theme }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">
    <title>{{ $pageTitle !== '' ? $pageTitle.' · '.config('app.name') : config('app.name') }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Chrome: Fraunces for display, Work Sans for everything functional, IBM
         Plex Mono only where a column of figures has to line up — the same
         three the guest pages already load, so the signed-in shell and the
         front door finally read as one product. Document defaults are
         unchanged: Source Serif 4 body, Source Sans 3 headings. --}}
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..600&family=Work+Sans:wght@400..600&family=IBM+Plex+Mono:wght@400;500&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    <a href="#canvas" class="skip-link">Skip to the document</a>

    <div class="shell" data-shell data-shell-context="{{ $isEditor ? 'editor' : 'page' }}">
        {{-- The flash banner is a ROW of the grid, not a sibling above it:
             outside the 100dvh grid it pushed the shell down and gave the
             document a second scrollbar the moment a flash fired. --}}
        <div class="shell-banner">
            <x-banner />
        </div>

        <x-shell.rail :document="$shellDocument" :state="$railState" />

        <main id="canvas" class="canvas-region" tabindex="-1">
            {{-- Jetstream's pages (profile, teams, API tokens) pass their
                 heading through the `header` slot. The shell renders it as the
                 page's one <h1>, so those pages are not headless inside it. --}}
            @isset($header)
                <div class="page">
                    <div class="page-head">
                        <h1 class="page-title">{{ $header }}</h1>
                    </div>
                    {{ $slot }}
                </div>
            @else
                {{ $slot }}
            @endisset
        </main>

        <x-shell.dock :document="$shellDocument" :state="$dockState" />

        <x-shell.topbar :rail-expanded="$railState === 'expanded'" :save-owner="$isEditor">
            <x-slot:title>
                {{ $shellDocument?->title ?: ($pageTitle !== '' ? $pageTitle : config('app.name')) }}
            </x-slot:title>

            <x-slot:actions>
                <button type="button"
                        class="topbar-action"
                        data-shell-theme-toggle
                        aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
                    <span data-shell-theme-word>{{ $theme === 'dark' ? 'Night' : 'Day' }}</span>
                    <span class="sr-only">Switch to {{ $theme === 'dark' ? 'day' : 'night' }} mode</span>
                </button>

                <button type="button"
                        class="topbar-action"
                        data-shell-panel-toggle="dock"
                        aria-controls="shell-dock"
                        aria-expanded="{{ $dockState === 'expanded' ? 'true' : 'false' }}">
                    <span data-shell-dock-word>{{ $dockState === 'expanded' ? 'Hide the tools' : 'Show the tools' }}</span>
                </button>
            </x-slot:actions>
        </x-shell.topbar>
    </div>

    @stack('modals')
    @livewireScripts
    @stack('scripts')
</body>
</html>
