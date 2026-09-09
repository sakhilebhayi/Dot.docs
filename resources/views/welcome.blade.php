<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Doc — Real-time collaborative documents</title>
        <meta name="description" content="Write together in real time with live cursors, threaded comments, full version history, and an AI writing assistant. Export to PDF, Word, or Markdown when you're done.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        <style>
            :root {
                --paper: #fbf7ee;
                --paper-deep: #f1e9d6;
                --ink: #1f1b14;
                --ink-soft: #5b5344;
                --line: rgba(31, 27, 20, 0.12);
                --line-soft: rgba(31, 27, 20, 0.08);
                --line-dark: rgba(245, 239, 222, 0.14);
                --gold: #f1c62e;
                --gold-soft: #f6d766;
                --gold-deep: #c9970f;
                --blue: #2487d4;
                --blue-deep: #1a6bae;
                --cream: #f5efde;
                --cream-80: rgba(245, 239, 222, 0.8);
                --cream-75: rgba(245, 239, 222, 0.75);
                --cream-40: rgba(245, 239, 222, 0.4);
                --ink-60: rgba(31, 27, 20, 0.6);
                --font-display: 'Fraunces', Georgia, serif;
                --font-body: 'Work Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(31, 27, 20, 0.03); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }

            header.is-scrolled {
                background: rgba(31, 27, 20, 0.92);
                backdrop-filter: blur(10px);
                border-bottom: 1px solid var(--line-dark);
            }
            #mobile-menu {
                display: none;
                opacity: 0;
                transition: opacity 150ms var(--ease-out);
            }
            #mobile-menu.is-open {
                display: block;
                opacity: 1;
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header id="site-header" class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300 border-b border-transparent">
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center gap-2.5 press">
                    {{-- Header overlays a hero photo with a fully-opaque
                         --ink scrim at this exact position (unlike the
                         footer below, which sits on the page's light paper
                         and keeps the default logo). --}}
                    <img src="{{ asset('images/logo-light.png') }}" alt="Dot.Doc" class="h-14 sm:h-16 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--cream)]">
                    <a href="#features" class="link-underline hover:text-white pb-0.5">Features</a>
                    <a href="#capabilities" class="link-underline hover:text-white pb-0.5">For teams</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[var(--ink)] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--cream)] hover:text-white transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[var(--ink)] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Start writing
                                </a>
                            @endif
                        @endauth

                        <button id="mobile-menu-toggle" class="md:hidden press p-2 -mr-2 text-[var(--cream)]" aria-label="Toggle menu" aria-expanded="false" aria-controls="mobile-menu">
                            <svg id="icon-menu" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                            </svg>
                            <svg id="icon-close" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div id="mobile-menu" class="md:hidden border-t border-[var(--line-dark)] bg-[var(--ink)]">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#features" class="px-3 py-2.5 text-[var(--cream)] hover:text-white">Features</a>
                    <a href="#capabilities" class="px-3 py-2.5 text-[var(--cream)] hover:text-white">For teams</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--cream)] hover:text-white">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-end overflow-hidden bg-[var(--ink)]">
            <!-- Photo: hands typing on a laptop, "type type type" by Christin Hume, unsplash.com/photos/mfB1B1s4sMc -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1515378960530-7c0da6231fb1?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(31,27,20,0.58) 0%, rgba(31,27,20,0.74) 45%, #1f1b14 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(90deg, #1f1b14 0%, rgba(31,27,20,0.55) 38%, transparent 68%);"></div>

            <!-- Open-folder silhouette — line-art nod to the real folder icon in the Dot.Doc mark -->
            <svg class="hidden lg:block absolute right-[6%] bottom-[14%] h-[54%] w-auto opacity-[0.14] pointer-events-none" viewBox="0 0 340 260" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M40,70 H150 L175,100 H300 V230 H40 Z" stroke="#f5efde" stroke-width="3" stroke-linejoin="round"/>
                <path d="M40,230 L95,145 H255 L300,230" stroke="#f5efde" stroke-width="3" stroke-linejoin="round"/>
                <path d="M95,148 L107,230 M245,148 L233,230" stroke="#f5efde" stroke-width="1.5"/>
                <circle cx="60" cy="100" r="4" stroke="#f5efde" stroke-width="1.5"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-32 pb-16 sm:pb-20 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                        Real-time document platform
                    </p>

                    <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.08] tracking-tight text-[var(--cream)] mb-6">
                        Every edit, seen live.<br>Every version, kept.
                    </h1>

                    <p class="text-lg text-[var(--cream-80)] leading-relaxed max-w-xl mb-10">
                        Dot.Doc is a real-time collaborative document platform: live cursors and presence, comments tied to the exact text, full version history with side-by-side diffs, and an AI assistant that drafts, rewrites, and summarizes without leaving the page.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[var(--ink)] font-display font-semibold rounded-lg transition-colors">
                                Start writing
                            </a>
                            <a href="#features" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--cream)] font-medium rounded-lg border border-[var(--line-dark)] hover:border-[var(--cream-40)] transition-colors">
                                See what's inside
                            </a>
                        </div>
                    @endguest
                </div>
            </div>

            <!-- Capability strip — real broadcast/collaboration features, not fabricated metrics -->
            <div class="relative z-10 w-full border-t border-[var(--line-dark)] bg-[var(--ink-60)] backdrop-blur-sm">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-8 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--cream-75)]">
                    <span>Live presence &amp; cursors</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Version history &amp; diffs</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Threaded comments</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>AI writing assist</span>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--blue-deep)] mb-4">What it does</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        One document, everyone in it at once
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $features = [
                            ['tag' => 'Editing', 'title' => 'Real-time collaborative editing', 'body' => 'Live cursors, active-user avatars, and instant content broadcast over presence channels — a rich-text editor with a 1.5s debounced autosave, so nothing is ever manually saved.'],
                            ['tag' => 'History', 'title' => 'Version history & diffs', 'body' => 'Every content change creates an immutable snapshot automatically. Browse the timeline, compare any two versions side by side, and restore an earlier draft in one click.'],
                            ['tag' => 'Comments', 'title' => 'Threaded comments', 'body' => 'Comments attach to the exact text selected, thread into replies, resolve when settled, and support @mentions — broadcast to collaborators the moment they\'re posted.'],
                            ['tag' => 'Sharing', 'title' => 'Flexible sharing', 'body' => 'A document can stay personal, belong to a team, list named collaborators with viewer, editor, or admin roles, or go public behind a password and an expiry date.'],
                            ['tag' => 'Assistant', 'title' => 'AI writing assistant', 'body' => 'Grammar checks, summaries, tone rewrites, translation, outlines, and continue-writing suggestions — plus a slash-command palette for prompts you define yourself.'],
                            ['tag' => 'Export', 'title' => 'Export & import', 'body' => 'Move documents in and out as PDF, Word, HTML, or Markdown — the same formats the rest of your team already works in.'],
                        ];
                    @endphp
                    @foreach ($features as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--blue-deep)] mb-3">{{ $f['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-2.5">{{ $f['title'] }}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed max-w-md">{{ $f['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Capabilities -->
        <section id="capabilities" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--paper-deep)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--blue-deep)] mb-4">Built for real ownership</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                            Not everything has to live in a team
                        </h2>
                        <p class="text-[var(--ink-soft)] leading-relaxed max-w-sm">
                            A document can be entirely personal, attached to a team, shared with named collaborators, or made public — Dot.Doc doesn't force every file through the same door.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Personal, team, or public', 'body' => 'Documents work standalone with an owner, or attach to a team — independent of who else can see them.'],
                                ['title' => 'Per-document roles', 'body' => 'Collaborators get viewer, editor, or admin access on a specific document, without needing team membership.'],
                                ['title' => 'Reusable templates', 'body' => 'Start from a global template, a team\'s own library, or one you saved yourself from an existing document.'],
                                ['title' => 'Custom slash commands', 'body' => 'Define a /command that expands into a prompt for the AI assistant — personal, or shared across your team.'],
                                ['title' => 'Outbound webhooks', 'body' => 'Fire a webhook on save or on export, per document, for whatever else needs to know a document changed.'],
                                ['title' => 'Password- & expiry-protected sharing', 'body' => 'Public links can require a password and stop working on a date you choose, not just an on/off toggle.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[var(--line)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-[var(--ink)] mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[var(--ink-soft)] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden bg-[var(--ink)]">
            <!-- Photo: two people reviewing documents at a table, by Olena Kholina, unsplash.com/photos/MhqUBTxQ3Hw -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1775163024488-e88e4a71179f?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, #1f1b14 0%, rgba(31,27,20,0.82) 50%, #1f1b14 100%);"></div>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--cream)] leading-tight mb-5">
                    Open a document, or bring your team into one
                </h2>
                <p class="text-[var(--cream-80)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Create an account and start writing — invite collaborators, share a public link, or keep it entirely to yourself.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[var(--ink)] font-display font-semibold rounded-lg transition-colors">
                            Start writing
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--cream)] font-medium rounded-lg border border-[var(--line-dark)] hover:border-[var(--cream-40)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Doc" class="h-11 w-auto opacity-90">
                </a>
                <div class="flex items-center gap-6 font-mono text-xs tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="{{ route('policy.show') }}" class="hover:text-[var(--ink)] transition-colors">Privacy</a>
                    <a href="{{ route('cookies') }}" class="hover:text-[var(--ink)] transition-colors">Cookies</a>
                    <a href="{{ route('terms.show') }}" class="hover:text-[var(--ink)] transition-colors">Terms</a>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Doc. Real-time collaborative documents.
                </p>
            </div>
        </footer>

        <script>
            // Nav scroll state + mobile menu — plain JS (this page has no Livewire-bundled
            // Alpine runtime available; app.js only starts Alpine via Livewire's own copy).
            (function () {
                const header = document.getElementById('site-header');
                const toggleScrolled = () => {
                    header.classList.toggle('is-scrolled', window.pageYOffset > 24);
                };
                toggleScrolled();
                window.addEventListener('scroll', toggleScrolled, { passive: true });

                const menuBtn = document.getElementById('mobile-menu-toggle');
                const menu = document.getElementById('mobile-menu');
                const iconMenu = document.getElementById('icon-menu');
                const iconClose = document.getElementById('icon-close');
                if (menuBtn && menu) {
                    menuBtn.addEventListener('click', () => {
                        const open = menu.classList.toggle('is-open');
                        menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                        iconMenu.classList.toggle('hidden', open);
                        iconClose.classList.toggle('hidden', !open);
                    });
                }
            })();

            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
