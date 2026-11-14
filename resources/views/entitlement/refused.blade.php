{{-- R-50: the company does not hold Dot.docs. A short page in the shell's own tokens and display face, with ONE
     action (data-primary-action). Rendered by App\Support\EntitlementRefusal with HTTP 403. It shows no document:
     no titles, no counts, no names but the company's own. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Dot.docs</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600&family=Work+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
</head>
<body>
    <main class="gate">
        <div class="gate-top">
            <span class="landing-logo">
                <img class="landing-logo-day" src="{{ asset('images/logo.png') }}" alt="Dot.docs" width="72" height="72">
                <img class="landing-logo-night" src="{{ asset('images/logo-light.png') }}" alt="Dot.docs" width="72" height="72">
            </span>
            @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="link gate-out">Sign out</button>
                </form>
            @endauth
        </div>

        <h1 class="gate-title">{{ $title }}</h1>
        <p class="gate-body">{{ $body }}</p>
        <p class="gate-actions">
            <a class="btn btn-primary landing-primary" href="{{ $platformsUrl }}" data-primary-action>Open Platforms in InfoDot</a>
        </p>

        @auth
            @if ($otherCompanies->isNotEmpty())
                <section class="gate-others" aria-labelledby="gate-others">
                    <h2 id="gate-others">Your other {{ $otherCompanies->count() === 1 ? 'company' : 'companies' }} here</h2>
                    <ul>
                        @foreach ($otherCompanies as $other)
                            <li>
                                <span>{{ $other->name }}</span>
                                <form method="POST" action="{{ route('current-team.update') }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="team_id" value="{{ $other->id }}">
                                    <button type="submit" class="btn btn-quiet" aria-label="Switch to {{ $other->name }}">Switch</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endauth
    </main>
</body>
</html>
