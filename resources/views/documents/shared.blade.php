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
    $theme = request()->cookie('theme') === 'light' ? 'light' : 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $theme === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">
    <title>{{ $document->title }} · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;500;700&family=Atkinson+Hyperlegible+Mono:wght@400;500&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#paper" class="skip-link">Skip to the document</a>

    <div class="shell" data-shell style="grid-template-columns:minmax(0,1fr);grid-template-areas:'status' 'desk'">
        <main id="paper" class="desk-region" tabindex="-1">
            <div class="desk">
                <style id="doc-style">{!! $styleCss !!}</style>
                <article class="paper">
                    {!! $document->content !!}
                </article>
            </div>
        </main>

        <header class="status-line" aria-label="Document status">
            <a class="status-brand" href="{{ url('/') }}" style="text-decoration:none">Dot.Doc</a>
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
            <button type="button" class="status-action" data-shell-theme-toggle
                    aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
                <span class="lamp {{ $theme === 'dark' ? 'lamp-idle' : 'lamp-signal' }}" aria-hidden="true"></span>
                <span data-shell-theme-word>{{ $theme === 'dark' ? 'Night' : 'Day' }}</span>
                <span class="sr-only">Switch to {{ $theme === 'dark' ? 'day' : 'night' }} mode</span>
            </button>
        </header>
    </div>
</body>
</html>
