<x-app-layout>
    {{-- The top bar names the PAGE, not the platform (spec §3), and this is the
         one page in the shell with no Livewire ->title() of its own. The word
         matches the rail's entry for it, so the two never disagree. --}}
    <x-slot name="title">Dashboard</x-slot>

    {{--
        The arrival page: where somebody lands after signing in through InfoDot.

        One thing is dominant here, the action that starts a document, and it is
        the only element carrying `data-primary-action`. Everything the old page
        let people reach is still here, demoted: the template gallery, the full
        list, what was shared, the counts (now one sentence instead of a panel
        of four figures).

        The sheet on the right is the product in miniature: the newest document
        in this company, in the document's own white (which does not invert at
        night, exactly like the real page). It is never a drawing of a document.
        With nothing written yet it is a blank sheet that says so.

        Every title, name and figure comes from App\Http\Controllers\LandingController,
        which reads one company only.
    --}}
    <div class="page landing">
        <header class="landing-mast">
            {{-- The original artwork, untouched: the day file on paper, the
                 light file at night. CSS shows one of the two. --}}
            <span class="landing-logo">
                <img class="landing-logo-day" src="{{ asset('images/logo.png') }}" alt="Dot.docs" width="88" height="88">
                <img class="landing-logo-night" src="{{ asset('images/logo-light.png') }}" alt="Dot.docs" width="88" height="88">
            </span>

            <nav class="landing-back" aria-label="InfoDot">
                <a class="link" href="{{ $infodotHome }}">InfoDot</a>
                <a class="link" href="{{ $infodotPlatforms }}">Platforms</a>
            </nav>
        </header>

        <div class="landing-lead">
            <div class="landing-intro">
                <h1 class="landing-title">Put it in writing, together.</h1>

                <p class="landing-welcome">
                    @if ($company)
                        Welcome, {{ $firstName }}. This is where {{ $company }} drafts, comments on and agrees
                        its documents, and every version is kept.
                    @else
                        Welcome, {{ $firstName }}. This is where you draft, comment on and agree documents,
                        and every version is kept.
                    @endif
                </p>

                <div class="landing-actions">
                    <a href="{{ route('documents.index', ['new' => 1]) }}" class="btn btn-primary landing-primary"
                       data-primary-action>Start a new document</a>
                    <a href="{{ route('documents.index', ['gallery' => 1]) }}" class="link">Start from a template</a>
                    <a href="{{ route('documents.index') }}" class="link">All documents</a>
                </div>
            </div>

            @if ($latest)
                <a class="landing-sheet" href="{{ route('documents.edit', $latest->uuid) }}"
                   aria-label="Open {{ $latest->title ?: 'Untitled' }}">
                    <span class="landing-sheet-title">{{ $latest->title ?: 'Untitled' }}</span>
                    @if ($excerpt !== '')
                        <span class="landing-sheet-text">{{ $excerpt }}</span>
                    @endif
                    <span class="landing-sheet-foot">
                        Edited {{ $latest->updated_at->diffForHumans() }}
                        @if ($latest->owner && $latest->owner_id !== auth()->id())
                            by {{ $latest->owner->name }}
                        @endif
                    </span>
                </a>
            @else
                <div class="landing-sheet landing-sheet-blank" data-landing-empty>
                    <span class="landing-sheet-text">
                        @if ($company)
                            Nothing has been written in {{ $company }} yet. The first page is yours to start.
                        @else
                            Nothing has been written here yet. The first page is yours to start.
                        @endif
                    </span>
                </div>
            @endif
        </div>

        @if ($recent->isNotEmpty())
            <section class="panel" aria-labelledby="dash-recent">
                <div class="panel-head">
                    <h2 class="section-title" id="dash-recent">Recently edited</h2>
                </div>

                <ul class="list">
                    @foreach ($recent as $doc)
                        <li>
                            <a href="{{ route('documents.edit', $doc->uuid) }}" class="list-row">
                                <span class="list-key">
                                    {{ $doc->title ?: 'Untitled' }}
                                    <span class="list-sub">
                                        Edited {{ $doc->updated_at->diffForHumans() }}@if ($doc->owner && $doc->owner_id !== auth()->id()) by {{ $doc->owner->name }}@endif
                                    </span>
                                </span>
                                @if ($doc->is_public)
                                    <x-shell.status-word tone="good" word="Public" />
                                @endif
                                @if ($doc->collaborators_count > 0)
                                    <span class="list-val">
                                        <x-shell.figure :value="$doc->collaborators_count" />&nbsp;sharing
                                    </span>
                                @endif
                                <span class="list-val">
                                    <x-shell.figure :value="$doc->version" prefix="v" label="Version" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($recentShared->isNotEmpty())
            <section class="panel" aria-labelledby="dash-shared">
                <div class="panel-head">
                    <h2 class="section-title" id="dash-shared">Shared with you</h2>
                    <a href="{{ route('documents.index', ['filter' => 'shared']) }}" class="link">Open the list</a>
                </div>

                <ul class="list">
                    @foreach ($recentShared as $collab)
                        @if ($collab->document)
                            <li>
                                <a href="{{ route('documents.edit', $collab->document->uuid) }}" class="list-row">
                                    <span class="list-key">
                                        {{ $collab->document->title ?: 'Untitled' }}
                                        <span class="list-sub">From {{ $collab->document->owner?->name ?? 'someone who left' }}</span>
                                    </span>
                                    <span class="list-val">{{ ucfirst($collab->role) }}</span>
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- The counts, as a sentence. A figure appears only when there is
             something to count, so nobody is shown a row of noughts. --}}
        <p class="landing-tally" data-landing-tally>
            @if ($documentCount === 0)
                No documents yet, and nobody has shared one with you by name.
                <a class="link" href="{{ route('documents.index', ['gallery' => 1]) }}">A template</a>
                is the quickest way to a first draft.
            @else
                <x-shell.figure :value="$documentCount" />
                {{ Str::plural('document', $documentCount) }} {{ $company ? 'in '.$company : 'here' }},
                <x-shell.figure :value="$mineCount" /> written by you.
                @if ($sharedCount > 0)
                    <x-shell.figure :value="$sharedCount" /> shared with you by name.
                @endif
                @if ($publishedCount > 0)
                    <x-shell.figure :value="$publishedCount" /> of yours published to anyone holding the link.
                @endif
                @if ($suggestionCount > 0)
                    <x-shell.figure :value="$suggestionCount" />
                    {{ Str::plural('suggestion', $suggestionCount) }} from the assistant still
                    {{ $suggestionCount === 1 ? 'waits' : 'wait' }} for you.
                @endif
            @endif
        </p>
    </div>
</x-app-layout>
