<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <title>{{ config('app.name', 'Dot.Doc') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles

    <style>
        :root {
            --paper: #fbf7ee;
            --paper-deep: #f1e9d6;
            --ink: #1f1b14;
            --ink-soft: #5b5344;
            --line: rgba(31, 27, 20, 0.12);
            --line-soft: rgba(31, 27, 20, 0.08);
            --gold: #f1c62e;
            --gold-soft: #f6d766;
            --gold-deep: #c9970f;
            --blue: #2487d4;
            --blue-deep: #1a6bae;
            --font-display: 'Fraunces', Georgia, serif;
            --font-body: 'Work Sans', system-ui, sans-serif;
            --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
        }
        html { background: var(--paper); }
        body { background: var(--paper); font-family: var(--font-body); }
        .font-display { font-family: var(--font-display); }
        .font-mono { font-family: var(--font-mono); }
    </style>
</head>
<body class="antialiased">
    <div class="font-sans text-[var(--ink)]">
        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>
