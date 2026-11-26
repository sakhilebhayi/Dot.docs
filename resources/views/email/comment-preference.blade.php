{{--
    The comment-email switch (EA-3 / E-06), reached from the link at the foot
    of a comment email. Built on the same frame as the password gate
    (documents/_password-gate): no shell, no sign-in, one decision.
--}}
@php
    $cookieTheme = request()->cookie('theme');
    $theme = $cookieTheme === 'dark' ? 'dark' : ($cookieTheme === 'light' ? 'light' : 'system');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto {{ $theme === 'system' ? '' : $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme === 'dark' ? 'dark light' : 'light dark' }}">
    <meta name="robots" content="noindex">
    <title>Comment emails · {{ config('app.name') }}</title>
    <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="page page-narrow" style="max-width:520px">
        <div class="page-head">
            <div>
                <h1 class="page-title">{{ $on ? 'Comment emails are on' : 'Comment emails are off' }}</h1>
                <p class="page-lede">
                    {{ $on
                        ? 'You get an email when someone comments on, replies to or mentions you in a document you are part of, unless you have already seen it in the app.'
                        : 'You will not get emails about comments, replies or mentions. They still show in the bell inside '.config('app.name').'.' }}
                </p>
            </div>
        </div>

        <section class="panel">
            <div class="panel-body">
                @if ($changed)
                    <p class="field-hint" role="status" style="margin-top:0">Saved.</p>
                @endif
                <form action="{{ $action }}" method="POST">
                    <button type="submit" class="btn {{ $on ? '' : 'btn-primary' }}">
                        {{ $on ? 'Turn comment emails off' : 'Turn comment emails back on' }}
                    </button>
                    <input type="hidden" name="state" value="{{ $on ? 'off' : 'on' }}">
                </form>
                <p class="field-hint">Emails about your account and your access to documents are always sent.</p>
            </div>
        </section>

        <p style="margin-top:var(--s4)">
            <a href="{{ url('/') }}" class="link">Back to {{ config('app.name') }}</a>
        </p>
    </main>
</body>
</html>
