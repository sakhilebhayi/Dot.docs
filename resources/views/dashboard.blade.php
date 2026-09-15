<x-app-layout>
    {{-- The top bar names the PAGE, not the platform (spec §3), and this is the
         one page in the shell with no Livewire ->title() of its own. The word
         matches the rail's entry for it, so the two never disagree. --}}
    <x-slot name="title">Dashboard</x-slot>

    <div class="page">
        <div class="page-head">
            <div>
                <h1 class="page-title">Your desk</h1>
                <p class="page-lede">
                    Everything you are writing, everything you have been asked to read, and what the assistant is
                    still waiting on you for.
                </p>
            </div>
            <a href="{{ route('documents.index') }}" class="btn btn-primary">New document</a>
        </div>

        {{-- The counts read as one list, not as four equal tiles: a plain
             numeral beside its name, in the order you would actually ask the
             questions. --}}
        <section class="panel" aria-labelledby="dash-counts">
            <div class="panel-head">
                <h2 class="section-title" id="dash-counts">Counts</h2>
                <span class="micro">{{ now()->format('D j M') }}</span>
            </div>
            <ul class="list">
                <li class="list-row">
                    <span class="list-key">
                        Documents you own
                        <span class="list-sub">Everything authored under this account.</span>
                    </span>
                    <span class="micro-lg list-figure">
                        <x-shell.figure :value="$myDocs" label="Documents you own" />
                    </span>
                </li>
                <li class="list-row">
                    <span class="list-key">
                        Shared with you
                        <span class="list-sub">Documents somebody added you to.</span>
                    </span>
                    <span class="micro-lg list-figure">
                        <x-shell.figure :value="$sharedDocs" label="Shared with you" />
                    </span>
                </li>
                <li class="list-row">
                    <span class="list-key">
                        Published
                        <span class="list-sub">Readable by anyone holding the link.</span>
                    </span>
                    <span class="micro-lg list-figure">
                        <x-shell.figure :value="$publicDocs" label="Published" />
                    </span>
                </li>
                <li class="list-row">
                    <span class="list-key">
                        Suggestions in marker
                        <span class="list-sub">The assistant's ink, still waiting to be accepted or dropped.</span>
                    </span>
                    {{-- The status word sits AFTER the label, like every other
                         one in the product, so the four figures stay in one
                         right-hand column. --}}
                    <x-shell.status-word :tone="$aiSuggestions > 0 ? 'good' : 'idle'"
                                         :word="$aiSuggestions > 0 ? 'Needs you' : 'Clear'" />
                    <span class="micro-lg list-figure">
                        <x-shell.figure :value="$aiSuggestions" label="Suggestions waiting" />
                    </span>
                </li>
            </ul>
        </section>

        <section class="panel" aria-labelledby="dash-recent">
            <div class="panel-head">
                <h2 class="section-title" id="dash-recent">Recently edited</h2>
                <a href="{{ route('documents.index') }}" class="link">All documents</a>
            </div>

            @if ($recentDocs->isEmpty())
                <div class="empty">
                    <p class="empty-line">Nothing has been written here yet.</p>
                    <a href="{{ route('documents.index') }}" class="btn btn-primary">New document</a>
                </div>
            @else
                <ul class="list">
                    @foreach ($recentDocs as $doc)
                        <li>
                            <a href="{{ route('documents.edit', $doc->uuid) }}" class="list-row">
                                <span class="list-key">
                                    {{ $doc->title ?: 'Untitled' }}
                                    <span class="list-sub">Edited {{ $doc->updated_at->diffForHumans() }}</span>
                                </span>
                                @if ($doc->is_public)
                                    <x-shell.status-word tone="good" word="Public" />
                                @endif
                                @if ($doc->collaborators->count() > 0)
                                    <span class="list-val">
                                        <x-shell.figure :value="$doc->collaborators->count()" />&nbsp;sharing
                                    </span>
                                @endif
                                <span class="list-val">
                                    <x-shell.figure :value="$doc->version" prefix="v" label="Version" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel" aria-labelledby="dash-shared">
            <div class="panel-head">
                <h2 class="section-title" id="dash-shared">Shared with you</h2>
                <a href="{{ route('documents.index', ['filter' => 'shared']) }}" class="link">Open the list</a>
            </div>

            @if ($recentShared->isEmpty())
                <div class="empty">
                    <p class="empty-line">Nobody has shared a document with you yet.</p>
                    <a href="{{ route('documents.index') }}" class="btn">Browse your own</a>
                </div>
            @else
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
            @endif
        </section>
    </div>
</x-app-layout>
