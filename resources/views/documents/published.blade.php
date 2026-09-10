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
    $theme = request()->cookie('theme') === 'light' ? 'light' : 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $theme === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">
    <meta name="robots" content="noindex">
    <title>{{ $document->title }} · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;500;700&family=Atkinson+Hyperlegible+Mono:wght@400;500&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
    <style>
        /*
         * Printing is the point of the button below, so paper is all that
         * survives it: no status line, no desk padding, and none of the
         * shadow that makes a sheet look like a sheet on screen. Layout
         * only - every colour on this page is a shell token.
         */
        @media print {
            .status-line,
            .published-foot,
            .skip-link { display: none !important; }

            .shell { display: block; height: auto; }
            .desk { padding: 0; }
            .desk .paper {
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

    <div class="shell" data-shell style="grid-template-columns:minmax(0,1fr);grid-template-areas:'status' 'desk'">
        <header class="status-line" aria-label="Document status">
            <a class="status-brand" href="{{ url('/') }}">Dot.Doc</a>
            <span class="status-item">
                <span class="status-item-key">Title</span>
                <span class="readout">{{ Str::limit($document->title, 48) }}</span>
            </span>
            <span class="status-item">
                <span class="status-item-key">By</span>
                <span class="readout">{{ $document->owner->name }}</span>
            </span>
            <span class="status-item">
                <span class="status-item-key">Updated</span>
                <span class="readout">{{ $document->updated_at->format('j M Y') }}</span>
            </span>
            <span class="status-spacer"></span>
            <button type="button" class="status-action" onclick="window.print()">
                <span class="lamp lamp-idle" aria-hidden="true"></span>
                <span>Print</span>
            </button>
            <button type="button" class="status-action" data-shell-theme-toggle
                    aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
                <span class="lamp {{ $theme === 'dark' ? 'lamp-idle' : 'lamp-signal' }}" aria-hidden="true"></span>
                <span data-shell-theme-word>{{ $theme === 'dark' ? 'Night' : 'Day' }}</span>
                <span class="sr-only">Switch to {{ $theme === 'dark' ? 'day' : 'night' }} mode</span>
            </button>
        </header>

        <main id="paper" class="desk-region" tabindex="-1">
            <div class="desk">
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
