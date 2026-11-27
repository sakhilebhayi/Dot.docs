@php
    /*
     * The published page. Same desk, same paper, same two inks - only the
     * chrome is stripped back to a single status line, because a reader has
     * nothing to navigate and nothing to save.
     *
     * The document's own style sheet is built here rather than in the route,
     * so both the direct view and the password-unlock POST render identically.
     */
    $engine = app(\App\Styles\StyleEngine::class);
    $styleCss = $engine->css($engine->resolve($document), 'canvas');
    // Rendered from the stored JSON by the same renderer as the published page (R2-1 / S-06), never the stored
    // HTML column printed raw: a legacy row's HTML was trusted as it was written.
    $body = app(\App\Documents\Render\NumberedRender::class)->html($document, \App\Documents\Render\RenderContext::share());
    // Day-first, like the signed-in shell: no cookie means no class and the
    // prefers-color-scheme guard in shell.css decides.
    $cookieTheme = request()->cookie('theme');
    $theme = $cookieTheme === 'dark' ? 'dark' : ($cookieTheme === 'light' ? 'light' : 'system');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto {{ $theme === 'system' ? '' : $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">
    <title>{{ $document->title }} · {{ config('app.name') }}</title>
    <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
    <style>
        /* Same print rules as the published page (EA-8 / U-06): paper only. */
        @media print {
            .topbar,
            .skip-link { display: none !important; }

            .shell { display: block; height: auto; }
            .canvas { padding: 0; }
            .canvas .paper {
                width: auto;
                min-height: 0;
                max-width: none;
                box-shadow: none;
                border-radius: 0;
            }
            @page { margin: 0; }
        }
    </style>
</head>
<body>
    <a href="#paper" class="skip-link">Skip to the document</a>

    <div class="shell" data-shell style="grid-template-columns:minmax(0,1fr);grid-template-areas:'topbar' 'canvas'">
        <header class="topbar" aria-label="Document status">
            <h1 class="topbar-title">{{ Str::limit($document->title, 64) }}</h1>

            <span class="micro">{{ $document->owner->name }} · {{ $document->updated_at->format('j M Y') }}</span>

            <div class="topbar-actions">
                <button type="button" class="topbar-action" data-shell-print>Print</button>
                <button type="button" class="topbar-action" data-shell-theme-toggle
                        aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
                    <span data-shell-theme-word>{{ $theme === 'dark' ? 'Night' : 'Day' }}</span>
                    <span class="sr-only">Switch to {{ $theme === 'dark' ? 'day' : 'night' }} mode</span>
                </button>
                <a class="topbar-action" href="{{ url('/') }}">Dot.docs</a>
            </div>
        </header>

        <main id="paper" class="canvas-region" tabindex="-1">
            <div class="canvas">
                <style id="doc-style">{!! $styleCss !!}</style>
                <article class="paper">
                    {!! $body !!}
                </article>
            </div>
        </main>
    </div>
</body>
</html>
