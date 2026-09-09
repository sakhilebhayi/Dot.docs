@php
    /*
     * "Two Inks on a Desk" — the application shell.
     *
     * Night is the default and lives on <html class="dark">; the `theme` cookie
     * is read HERE, server-side, so a day-mode reload never flashes night. The
     * cookie is excluded from Laravel's cookie encryption in bootstrap/app.php,
     * because resources/js/shell.js writes it from the browser.
     *
     * Nothing loads from a CDN: Tailwind and Alpine both come from the Vite
     * bundle (Alpine only ever through Livewire's own copy — a second Alpine
     * wins the window.Alpine slot and kills every wire: binding on the page).
     *
     * DOM order inside .shell is rail -> desk -> dock -> status line, and the
     * grid puts the status line back on top. That is deliberate: Tab has to run
     * skip link -> rail -> paper -> dock before it reaches the theme toggle.
     */
    $theme = request()->cookie('theme') === 'light' ? 'light' : 'dark';
    $shellDocument = app(\App\Support\ShellContext::class)->document();
    $pageTitle = trim((string) ($title ?? ''));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $theme === 'dark' ? 'dark' : '' }}">
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
    {{-- Chrome: Atkinson Hyperlegible Next / Mono (Braille Institute, drawn for
         legibility). Document defaults: Source Serif 4 body, Source Sans 3 headings. --}}
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;500;700&family=Atkinson+Hyperlegible+Mono:wght@400;500&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    <a href="#desk" class="skip-link">Skip to the document</a>

    <div class="shell" data-shell>
        {{-- The flash banner is a ROW of the grid, not a sibling above it:
             outside the 100dvh grid it pushed the shell down and gave the
             document a second scrollbar the moment a flash fired. --}}
        <div class="shell-banner">
            <x-banner />
        </div>

        <x-shell.rail :document="$shellDocument" />

        <main id="desk" class="desk-region" tabindex="-1">
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

        <x-shell.dock :document="$shellDocument" />

        <x-shell.status-line :document="$shellDocument" :theme="$theme" />
    </div>

    @stack('modals')
    @livewireScripts
    @stack('scripts')
</body>
</html>
