<x-app-layout>
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

        {{-- The counts read as one ledger, not as four equal tiles: a figure is
             a mono readout beside its name, in the order you would actually
             ask the questions. --}}
        <section class="panel" aria-labelledby="dash-counts">
            <div class="panel-head">
                <h2 class="section-title" id="dash-counts">Counts</h2>
                <span class="readout">{{ now()->format('D j M') }}</span>
            </div>
            <ul class="ledger">
                <li class="ledger-row">
                    <span class="ledger-key">
                        Documents you own
                        <span class="ledger-sub">Everything authored under this account.</span>
                    </span>
                    <span class="readout readout-lg">
                        <x-shell.figure :value="$myDocs" :width="3" label="Documents you own" />
                    </span>
                </li>
                <li class="ledger-row">
                    <span class="ledger-key">
                        Shared with you
                        <span class="ledger-sub">Documents somebody added you to.</span>
                    </span>
                    <span class="readout readout-lg">
                        <x-shell.figure :value="$sharedDocs" :width="3" label="Shared with you" />
                    </span>
                </li>
                <li class="ledger-row">
                    <span class="ledger-key">
                        Published
                        <span class="ledger-sub">Readable by anyone holding the link.</span>
                    </span>
                    <span class="readout readout-lg">
                        <x-shell.figure :value="$publicDocs" :width="3" label="Published" />
                    </span>
                </li>
                <li class="ledger-row">
                    <x-shell.lamp :tone="$aiSuggestions > 0 ? 'signal' : 'idle'"
                                  :word="$aiSuggestions > 0 ? 'Needs you' : 'Clear'"
                                  style="padding:0;border-right:0" />
                    <span class="ledger-key">
                        Suggestions in marker
                        <span class="ledger-sub">The assistant's ink, still waiting to be accepted or dropped.</span>
                    </span>
                    <span class="readout readout-lg">
                        <x-shell.figure :value="$aiSuggestions" :width="3" label="Suggestions waiting" />
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
                <ul class="ledger">
                    @foreach ($recentDocs as $doc)
                        <li>
                            <a href="{{ route('documents.edit', $doc->uuid) }}" class="ledger-row">
                                <span class="ledger-key">
                                    {{ $doc->title ?: 'Untitled' }}
                                    <span class="ledger-sub">Edited {{ $doc->updated_at->diffForHumans() }}</span>
                                </span>
                                @if ($doc->is_public)
                                    <x-shell.lamp tone="signal" word="Public" style="padding:0;border-right:0" />
                                @endif
                                @if ($doc->collaborators->count() > 0)
                                    <span class="ledger-val">
                                        <x-shell.figure :value="$doc->collaborators->count()" :width="2" label="Collaborators" />
                                        &nbsp;sharing
                                    </span>
                                @endif
                                <span class="ledger-val">
                                    <x-shell.figure :value="$doc->version" :width="4" prefix="v" label="Version" />
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
                <ul class="ledger">
                    @foreach ($recentShared as $collab)
                        @if ($collab->document)
                            <li>
                                <a href="{{ route('documents.edit', $collab->document->uuid) }}" class="ledger-row">
                                    <span class="ledger-key">
                                        {{ $collab->document->title ?: 'Untitled' }}
                                        <span class="ledger-sub">From {{ $collab->document->owner?->name ?? 'someone who left' }}</span>
                                    </span>
                                    <span class="ledger-val">{{ ucfirst($collab->role) }}</span>
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-app-layout>
