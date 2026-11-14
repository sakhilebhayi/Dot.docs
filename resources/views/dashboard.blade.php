<x-app-layout>
    {{-- The top bar names the PAGE, not the platform (spec §3), and this is the
         one page in the shell with no Livewire ->title() of its own. The word
         matches the rail's entry for it, so the two never disagree. --}}
    <x-slot name="title">Dashboard</x-slot>

    {{--
        The arrival page: where somebody lands after signing in through InfoDot.

        Three things, in the order a person needs them: ONE true sentence about
        where the company's writing stands (and the one filled action that
        starts a document), a table of the twelve most recently edited
        documents, and the record of the selected one beside it. The record is
        selected with `?doc=` (server-rendered, shareable, no script needed).

        `data-primary-action` is on exactly one element. The sheet in the record
        is the product in miniature: the selected document in the document's own
        white, which does not invert at night. It is never a drawing of a
        document; with nothing written yet it is a blank ruled sheet that says so.

        Every title, name and figure comes from App\Http\Controllers\LandingController,
        which reads one company only. Motion lives in resources/css/shell.css
        (arrival, rows, record) and is removed by prefers-reduced-motion.
    --}}
    <div class="page landing">
        <section class="arrive{{ $arriving ? ' is-arriving' : '' }}" aria-label="Arrival">
            {{-- The original artwork, untouched: the day file on paper, the
                 light file at night. CSS shows one of the two. --}}
            <span class="landing-logo arrive-logo">
                <img class="landing-logo-day" src="{{ asset('images/logo.png') }}" alt="Dot.docs" width="72" height="72">
                <img class="landing-logo-night" src="{{ asset('images/logo-light.png') }}" alt="Dot.docs" width="72" height="72">
            </span>

            <div class="arrive-copy">
                <h1 class="arrive-title">{{ $headline }}</h1>
                <p class="arrive-sub">
                    @if ($suggestionDocument)
                        It is on <a href="{{ route('documents.edit', $suggestionDocument->uuid) }}">{{ $suggestionDocument->title ?: 'Untitled' }}</a>.
                        Everything {{ $company ? 'in '.$company : 'here' }} is kept in versions.
                    @elseif ($selected)
                        Everything {{ $company ? 'in '.$company : 'here' }} is kept in versions.
                    @else
                        The first page is yours to start. A template is the quickest way to a first draft.
                    @endif
                </p>
            </div>

            <div class="arrive-actions">
                <a href="{{ route('documents.index', ['new' => 1]) }}" class="btn btn-primary"
                   data-primary-action>Start a new document</a>
                <a href="{{ route('documents.index', ['gallery' => 1]) }}" class="link">Start from a template</a>
                <form class="find" role="search" method="get" action="{{ route('documents.index') }}">
                    <x-dot.filter-search name="q" label="Search titles and text" placeholder="Find a document" />
                </form>
            </div>
            <span class="arrive-rule" aria-hidden="true"></span>
        </section>

        <div class="desk">
            <div>
                <section aria-labelledby="dash-docs">
                    <div class="sec-head">
                        <h2 id="dash-docs">{{ $rows->isNotEmpty() ? 'Recently edited' : 'Documents' }} {{ $company ? 'in '.$company : 'here' }}</h2>
                        <a href="{{ route('documents.index') }}" class="link">All documents</a>
                    </div>

                    @if ($rows->isEmpty())
                        <p class="empty-rows">Documents you write, and documents shared with you, are listed on this page.</p>
                    @else
                        <table class="tbl" data-sortable>
                            <colgroup><col><col class="c-edited"><col class="c-by"><col class="c-ver"><col class="c-open"></colgroup>
                            <thead>
                                <tr>
                                    <th scope="col" data-sort-key="title">Document</th>
                                    <th scope="col" class="col-edited" data-sort-key="edited" aria-sort="descending">Edited</th>
                                    <th scope="col" class="col-by">Written by</th>
                                    <th scope="col" class="num col-ver" data-sort-key="version">Version</th>
                                    <th scope="col"><span class="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $doc)
                                    @php($name = $doc->title ?: 'Untitled')
                                    <tr data-sort-title="{{ \Illuminate\Support\Str::lower($name) }}"
                                        data-sort-edited="{{ $doc->updated_at->timestamp }}"
                                        data-sort-version="{{ (int) $doc->version }}"
                                        @if ($selected && $doc->is($selected)) aria-current="true" @endif>
                                        <td class="t">
                                            <a class="t-name row-select" href="{{ route('dashboard', ['doc' => $doc->uuid]) }}">{{ $name }}</a>
                                            <a class="t-name row-direct" href="{{ route('documents.edit', $doc->uuid) }}">{{ $name }}</a>
                                            <span class="t-sub">
                                                {{ number_format($doc->word_count) }} {{ \Illuminate\Support\Str::plural('word', $doc->word_count) }} &middot;
                                                <x-shell.status-word :tone="$doc->is_public ? 'good' : 'idle'" :word="$doc->is_public ? 'Public' : 'Private'" />
                                                <span class="on-phone">&middot; edited {{ $doc->updated_at->diffForHumans() }}</span>
                                            </span>
                                        </td>
                                        <td class="soft col-edited">{{ $doc->updated_at->diffForHumans() }}</td>
                                        <td class="soft col-by">{{ $doc->owner_id === auth()->id() ? 'You' : ($doc->owner?->name ?? 'Someone who left') }}</td>
                                        <td class="soft num col-ver"><x-shell.figure :value="$doc->version" prefix="v" label="Version" /></td>
                                        <td class="num"><a class="row-open" href="{{ route('documents.edit', $doc->uuid) }}" aria-label="Open {{ $name }}">Open</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if ($rows->count() >= $listLength)
                            <p class="desk-note">The {{ $listLength }} most recently edited. All documents has the rest.</p>
                        @endif
                    @endif
                </section>

                @if ($recentShared->isNotEmpty())
                    <section class="shared" aria-labelledby="dash-shared">
                        <div class="sec-head">
                            <h2 id="dash-shared">Shared with you by name</h2>
                            <a href="{{ route('documents.index', ['filter' => 'shared']) }}" class="link">Open the list</a>
                        </div>
                        <table class="tbl">
                            <colgroup><col><col class="c-by"><col class="c-role"><col class="c-open"></colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">Document</th>
                                    <th scope="col" class="col-by">From</th>
                                    <th scope="col" class="num">Your role</th>
                                    <th scope="col"><span class="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentShared as $collab)
                                    @if ($collab->document)
                                        @php($sname = $collab->document->title ?: 'Untitled')
                                        <tr>
                                            <td class="t">
                                                <a class="t-name" href="{{ route('documents.edit', $collab->document->uuid) }}">{{ $sname }}</a>
                                                <span class="t-sub">From {{ $collab->document->owner?->name ?? 'someone who left' }}</span>
                                            </td>
                                            <td class="soft col-by">{{ $collab->document->owner?->name ?? 'Someone who left' }}</td>
                                            <td class="soft num">{{ ucfirst($collab->role) }}</td>
                                            <td class="num"><a class="row-open" href="{{ route('documents.edit', $collab->document->uuid) }}" aria-label="Open {{ $sname }}">Open</a></td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif
            </div>

            @if ($selected)
                @php($selectedName = $selected->title ?: 'Untitled')
                @php($layers = $selected->version > 2 ? 2 : ($selected->version > 1 ? 1 : 0))
                <aside class="detail" aria-labelledby="dash-record">
                    <div class="sec-head"><h2 id="dash-record">Document record</h2></div>

                    {{-- The sheet carries one thin layer behind it per earlier version, up to two:
                         the page is the newest of a kept stack, which is what versioning means here. --}}
                    <div class="rec-stack" data-layers="{{ $layers }}">
                        @for ($i = 0; $i < $layers; $i++)
                            <span class="rec-under" aria-hidden="true"></span>
                        @endfor
                        <a class="rec-sheet" href="{{ route('documents.edit', $selected->uuid) }}"
                           aria-label="Open {{ $selectedName }}">
                            <span class="rec-title">{{ $selectedName }}</span>
                            @if ($excerpt !== '')
                                <span class="rec-text">{{ $excerpt }}</span>
                            @endif
                        </a>
                    </div>

                    <dl class="facts">
                        <div><dt>Edited</dt><dd>{{ $selected->updated_at->diffForHumans() }}</dd></div>
                        <div><dt>Written by</dt><dd>{{ $selected->owner_id === auth()->id() ? 'You' : ($selected->owner?->name ?? 'Someone who left') }}</dd></div>
                        <div><dt>Version</dt><dd>{{ number_format((int) $selected->version) }}</dd></div>
                        <div><dt>Words</dt><dd>{{ number_format($selected->word_count) }}</dd></div>
                        @if ($selected->collaborators_count > 0)
                            <div><dt>Sharing</dt><dd>{{ number_format($selected->collaborators_count) }} {{ \Illuminate\Support\Str::plural('person', $selected->collaborators_count) }} by name</dd></div>
                        @endif
                        <div>
                            <dt>Published</dt>
                            <dd>
                                @if ($selected->is_public)
                                    <x-shell.status-word tone="good" word="Public" />
                                    &middot; {{ number_format((int) $selected->view_count) }} {{ \Illuminate\Support\Str::plural('view', (int) $selected->view_count) }}
                                @else
                                    Not published
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="acts">
                        <a class="btn" href="{{ route('documents.edit', $selected->uuid) }}">Open</a>
                        <a class="btn" href="{{ route('documents.history', $selected->uuid) }}">Versions</a>
                        @if ($canUpdate)
                            <a class="btn" href="{{ route('documents.share', $selected->uuid) }}">Share</a>
                            <a class="btn" href="{{ route('documents.settings', $selected->uuid) }}">Settings</a>
                        @endif
                    </div>
                </aside>
            @else
                <aside class="detail" aria-label="The first page">
                    <div class="sec-head"><h2>A blank page</h2></div>
                    <div class="blank-sheet" data-landing-empty>
                        @if ($company)
                            Nothing has been written in {{ $company }} yet. The first page is yours to start.
                        @else
                            Nothing has been written here yet. The first page is yours to start.
                        @endif
                    </div>
                </aside>
            @endif
        </div>

        {{-- The counts, as a sentence. A figure appears only when there is
             something to count, so nobody is shown a row of noughts. --}}
        <footer class="foot">
            <p data-landing-tally>
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
            <a class="link" href="{{ $infodotHome }}">Back to InfoDot</a>
        </footer>
    </div>
</x-app-layout>
