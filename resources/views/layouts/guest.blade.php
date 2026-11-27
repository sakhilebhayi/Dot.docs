<!DOCTYPE html>
{{-- `light` pins this page to its own warm day palette: shell.css's night
     guard is `html:not(.light)` under prefers-color-scheme: dark, and the
     marketing and auth pages have never had a night mode of their own. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <title>{{ config('app.name', 'Dot.docs') }}</title>

    <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles

    <style>
        /* R2-9: these pages wear Fair Copy (resources/css/shell.css, loaded through app.css): its ground, ink, lines
           and accent. The names the pages already use for links map onto the accent; nothing here defines a colour of
           its own any more. */
        :root {
            --blue: var(--accent);
            --blue-deep: var(--accent);
            --font-display: var(--dot-font-sans);
            --font-body: var(--dot-font-sans);
        }
        html { background: var(--surface); }
        body { background: var(--surface); color: var(--ink); font-family: var(--font-body); }
        .font-display { font-family: var(--font-display); }
        .font-mono { font-family: var(--font-mono); }
        /* R-54 claim page: the code field, in the same palette */
        .dsso-code { text-align: center; font-size: 1.4rem; font-weight: 600; letter-spacing: 0.3em; font-variant-numeric: tabular-nums; min-height: 48px; }
        .dsso-code[aria-invalid="true"] { border-color: var(--danger); }
        .dsso-err:empty { display: none; }
    </style>
</head>
<body class="antialiased">
    <main id="main" class="font-sans text-[var(--ink)]">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
