@php
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
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;500;700&family=Atkinson+Hyperlegible+Mono:wght@400;500&display=swap" rel="stylesheet">
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
                <span class="lamp lamp-signal" aria-hidden="true"></span>
                <h2 class="section-title" style="flex:1 1 auto">Password required</h2>
            </div>
            <div class="panel-body">
                <form action="{{ route('documents.shared.unlock', $document->uuid) }}" method="POST" class="stack">
                    @csrf
                    <div class="field-row" style="margin-top:0">
                        <label class="field-label" for="password">Password</label>
                        <input id="password" type="password" name="password" class="field" autofocus required />
                        @error('password')
                            <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
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
