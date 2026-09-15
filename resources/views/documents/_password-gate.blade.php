{{--
    The password gate both public read surfaces share: /shared/{uuid} and
    /d/{slug} ask for the same thing the same way, differing only in where
    the form posts and whether the page carries `noindex` (see
    .ai/rules/publishing.md). Included with `$document` already in scope
    from the caller, plus `$action` (required) and `$noindex` (optional,
    default false).
--}}
@php
    // Day-first, like the rest of the product: no cookie means no class and the
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
    @if ($noindex ?? false)
        <meta name="robots" content="noindex">
    @endif
    <title>{{ $document->title }} · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..600&family=Work+Sans:wght@400..600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="page page-narrow" style="max-width:520px">
        <div class="page-head">
            <div>
                <h1 class="page-title">This one is locked</h1>
                <p class="page-lede">{{ $document->title }} needs the password its owner set on the link.</p>
            </div>
        </div>

        <section class="panel">
            <div class="panel-head">
                <h2 class="section-title">Password required</h2>
            </div>
            <div class="panel-body">
                <form action="{{ $action }}" method="POST" class="stack">
                    @csrf
                    <div class="field-row" style="margin-top:0">
                        <label class="field-label" for="password">Password</label>
                        <input id="password" type="password" name="password" class="field" autofocus required />
                        @error('password')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">Open the document</button>
                </form>
            </div>
        </section>

        <p style="margin-top:var(--s4)">
            <a href="{{ url('/') }}" class="link">Back to {{ config('app.name') }}</a>
        </p>
    </main>
</body>
</html>
