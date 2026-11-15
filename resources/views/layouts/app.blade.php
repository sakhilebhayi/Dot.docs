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
     * DOM order inside .shell MATCHES what is on screen: top bar, then rail,
     * canvas, dock. The bar used to be last (the grid puts it back on top
     * whatever the order), on the reasoning that Tab should reach the document
     * before the chrome — but the bar stopped being a status readout in this
     * redesign and became the page's primary navigation. Both panels default
     * to COLLAPSED on the editor, and a collapsed panel is `display: none`, so
     * the only controls that can open either one are up here: last in the tab
     * order they were a keyboard reader's first visible control and their final
     * tab stop, after the entire document. The skip link is what puts the
     * document first for anyone who wants it there.
     *
     * It is also what lets the editor's floating toolbar be reached by Tab at
     * all: it is appended to <body> (resources/js/editor/ui/bubble.js explains
     * why it cannot live inside the canvas), so with the bar last, tabbing out
     * of the paper hit the bar's rail toggle first and the toolbar hid itself
     * before focus could ever come round to it.
     */
    $cookieTheme = request()->cookie('theme');
    $theme = $cookieTheme === 'dark' ? 'dark' : ($cookieTheme === 'light' ? 'light' : 'system');
    $shellDocument = app(\App\Support\ShellContext::class)->document();

    /*
     * What this page is called, in its own words.
     *
     * A full-page Livewire component says it with ->title(); a Jetstream page
     * says it through the `header` slot; anything else can pass a `title` slot.
     * The last resort is the route's own first word — NEVER config('app.name').
     * Spec §3 retired the platform-name readout, and a top bar that falls back
     * to "Dot.docs" in Fraunces is that readout with a new typeface. The brand
     * keeps the tab title and the rail's colophon, which is where a brand goes.
     */
    $pageTitle = trim(strip_tags((string) ($title ?? '')));

    if ($pageTitle === '') {
        $pageTitle = trim(strip_tags((string) ($header ?? '')));
    }

    // The editor is the one route with a canvas competing for width, so it is
    // the one route where both panels start collapsed (spec §2.4). Everywhere
    // else the rail is the page's navigation and stays open.
    $isEditor = request()->routeIs('documents.edit');

    $sectionName = \Illuminate\Support\Str::headline(
        \Illuminate\Support\Str::before((string) request()->route()?->getName(), '.')
    );

    /*
     * The editor is the one route that does NOT put the document's title up
     * here, and it is the only page in the product that owns an inline,
     * editable copy of it: `.doc-bar`'s title field, right under this bar.
     * A title you can READ in two places but EDIT in one is a title people try
     * to edit in the wrong one, and Task 1 shipped it twice. So the editor
     * names its section instead, and everywhere else — history, share,
     * settings — still names the document it is about, because on those pages
     * nothing else does.
     */
    $topbarTitle = $isEditor
        ? $sectionName
        : ($shellDocument?->title ?: ($pageTitle !== '' ? $pageTitle : $sectionName));
    $railState = $isEditor ? 'collapsed' : 'expanded';
    // The arrival page is the second route where the tools start put away: it
    // has one action to offer, and an open dock beside it both squeezes the
    // page and puts a second call to action next to the first. The rail stays
    // open there, because the rail is how you leave.
    $dockState = ($isEditor || request()->routeIs('dashboard')) ? 'collapsed' : 'expanded';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto{{ $theme === 'system' ? '' : ' '.$theme }}"
      data-dh-theme-class="dark" data-dh-theme-key="dot-docs-theme" data-dh-theme-default="{{ $theme }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">
    <title>{{ $pageTitle !== '' ? $pageTitle.' · '.config('app.name') : config('app.name') }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    {{-- Roboto, self-hosted (public/dot-ui/fonts/roboto): the one face of every Dot platform. No external font host.
         Document faces are the document's own (DocumentStyle) and load on the document, never for the chrome. --}}
    <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-header.css') }}">
    <script src="{{ asset('dot-ui/js/dot-theme-boot.js') }}"></script>
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    <a href="#canvas" class="skip-link">Skip to the document</a>

    <div class="shell" data-shell data-shell-context="{{ $isEditor ? 'editor' : 'page' }}"@if (request()->routeIs('dashboard')) data-shell-page="dashboard"@endif>
        @include('layouts.partials.header', ['isEditor' => $isEditor, 'railState' => $railState, 'dockState' => $dockState, 'theme' => $theme])

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
    </div>

    @stack('modals')
    <script src="{{ asset('dot-ui/js/dot-search.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-search-results.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-switcher.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-nav.js') }}" defer></script>
    @livewireScripts
    @stack('scripts')
</body>
</html>
