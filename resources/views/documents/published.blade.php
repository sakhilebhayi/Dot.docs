@php
    /*
     * The published page (/d/{slug}).
     *
     * The desk, the paper and the two inks are the shell's own (shell.css /
     * paper.css); the document inside .paper is styled only by its Document
     * Style CSS, built in 'share' mode - canvas without the editor's chrome
     * (see App\Styles\CssBuilder). $body arrives already numbered, with the
     * table of contents filled in and variables resolved.
     */
    // Day-first, like the signed-in shell: no cookie means no class and the
    // prefers-color-scheme guard in shell.css decides.
    $cookieTheme = request()->cookie('theme');
    $theme = $cookieTheme === 'dark' ? 'dark' : ($cookieTheme === 'light' ? 'light' : 'system');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $theme === 'system' ? '' : $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">
    <meta name="robots" content="noindex">
    <title>{{ $document->title }} · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..600&family=Work+Sans:wght@400..600&family=IBM+Plex+Mono:wght@400;500&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
    <style>
        /*
         * Printing is the point of the button below, so paper is all that
         * survives it: no status line, no desk padding, and none of the
         * shadow that makes a sheet look like a sheet on screen. Layout
         * only - every colour on this page is a shell token.
         */
        @media print {
            .topbar,
            .published-foot,
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
            <div class="topbar-title">{{ Str::limit($document->title, 64) }}</div>

            <span class="micro">{{ $document->owner->name }} · {{ $document->updated_at->format('j M Y') }}</span>

            <div class="topbar-actions">
            <button type="button" class="topbar-action" onclick="window.print()">Print</button>
                <button type="button" class="topbar-action" data-shell-theme-toggle
                        aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
                    <span data-shell-theme-word>{{ $theme === 'dark' ? 'Night' : 'Day' }}</span>
                    <span class="sr-only">Switch to {{ $theme === 'dark' ? 'day' : 'night' }} mode</span>
                </button>
                <a class="topbar-action" href="{{ url('/') }}">Dot.Doc</a>
            </div>
        </header>

        <main id="paper" class="canvas-region" tabindex="-1">
            <div class="canvas">
                <style id="doc-style">{!! $styleCss !!}</style>
                <article class="paper">
                    {!! $body !!}
                </article>
                <p class="page-lede published-foot" style="flex:0 0 100%;margin:var(--s5) 0 0;text-align:center">
                    Made with Dot.Doc
                </p>
            </div>
        </main>
    </div>
</body>
</html>
