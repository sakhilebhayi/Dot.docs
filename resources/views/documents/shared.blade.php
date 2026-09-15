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
    <title>{{ $document->title }} · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..600&family=Work+Sans:wght@400..600&family=IBM+Plex+Mono:wght@400;500&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#paper" class="skip-link">Skip to the document</a>

    <div class="shell" data-shell style="grid-template-columns:minmax(0,1fr);grid-template-areas:'topbar' 'canvas'">
        <main id="paper" class="canvas-region" tabindex="-1">
            <div class="canvas">
                <style id="doc-style">{!! $styleCss !!}</style>
                <article class="paper">
                    {!! $document->content !!}
                </article>
            </div>
        </main>

        <header class="topbar" aria-label="Document status">
            <div class="topbar-title">{{ Str::limit($document->title, 64) }}</div>

            <span class="micro">{{ $document->owner->name }} · {{ $document->updated_at->format('j M Y') }}</span>

            <div class="topbar-actions">
                <button type="button" class="topbar-action" data-shell-theme-toggle
                        aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
                    <span data-shell-theme-word>{{ $theme === 'dark' ? 'Night' : 'Day' }}</span>
                    <span class="sr-only">Switch to {{ $theme === 'dark' ? 'day' : 'night' }} mode</span>
                </button>
                <a class="topbar-action" href="{{ url('/') }}">Dot.Doc</a>
            </div>
        </header>
    </div>
</body>
</html>
