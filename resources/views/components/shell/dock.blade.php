{{--
    The right panel: two tabs. "Intelligence" is what the machine has to say,
    "Data" is what the record says. Rows inside are separated by space, not by
    hairlines, and nothing in here is a card.

    It defaults to COLLAPSED on the editor (spec §2.4) and opens when the writer
    does something that needs it - invoking the assistant, opening comments,
    attaching a file - or from its toggle at the right of the top bar. `state`
    is rendered by the layout so it never flashes open.

    The tabs follow the WAI-ARIA APG tabs pattern: roving tabindex, arrow keys,
    Home/End, and every tab wired to its panel with aria-controls /
    aria-labelledby. resources/js/shell.js drives the keyboard half.

    The assistant lives HERE, at the foot of the Intelligence tab - it has no
    window of its own. Nothing in this product floats except the paper.

    Alpine here is Livewire's bundled copy (the layout loads no second one).
--}}
@props(['document' => null, 'state' => 'expanded'])

@php
    $user = auth()->user();
    $isEditor = request()->routeIs('documents.edit');
@endphp

<aside id="shell-dock" class="dock" data-panel-state="{{ $state }}"
       aria-label="Intelligence and data" x-data="{ tab: 'intelligence' }" data-shell-tabs>
    {{-- Same overlay close control the rail carries, for the same reason: below
         900px this panel is covering the page. See components/shell/rail. --}}
    <div class="panel-overlay-head">
        <button type="button" class="btn btn-sm" data-shell-panel-toggle="dock" aria-controls="shell-dock">
            Close the tools
        </button>
    </div>

    <div class="dock-tabs" role="tablist" aria-label="Dock sections">
        <button type="button" class="dock-tab" role="tab"
                id="dock-tab-intelligence"
                aria-controls="dock-panel-intelligence"
                :aria-selected="tab === 'intelligence' ? 'true' : 'false'"
                aria-selected="true"
                @click="tab = 'intelligence'">Intelligence</button>
        <button type="button" class="dock-tab" role="tab"
                id="dock-tab-data"
                aria-controls="dock-panel-data"
                :aria-selected="tab === 'data' ? 'true' : 'false'"
                aria-selected="false"
                tabindex="-1"
                @click="tab = 'data'">Data</button>
    </div>

    <div class="dock-body" role="tabpanel" id="dock-panel-intelligence"
         aria-labelledby="dock-tab-intelligence" tabindex="0" x-show="tab === 'intelligence'">
        @if ($isEditor && $document)
            <section class="dock-section">
                <div class="dock-section-head">
                    <h2 class="h-panel">Two inks</h2>
                </div>
                <div class="dock-section-body">
                    <p class="empty-line">
                        The assistant writes in marker. Its ink stays visibly different until you accept it,
                        and only then becomes graphite.
                    </p>
                    <div class="toolbar" x-data>
                        {{-- The shortcut inherits the button's ink: a .micro
                             pins --ink-soft, which on an inverted fill is
                             unreadable — see the inverted-surface block in
                             shell.css. --}}
                        <button type="button" class="btn btn-primary" @click="$dispatch('open-ai-palette')">
                            Open the command palette
                            <span class="micro" aria-hidden="true">&#8679;&#8984;K</span>
                        </button>
                    </div>
                </div>
            </section>

            <section class="dock-section" x-data>
                <div class="dock-section-head">
                    <h2 class="section-title">Quick passes</h2>
                </div>
                <ul class="list">
                    @foreach ([
                        ['summarize', 'Summarize', 'A short abstract of the whole document.'],
                        ['grammar', 'Fix grammar', 'Spelling and grammar only; wording is left alone.'],
                        ['continue', 'Continue writing', 'Carries on from where the cursor sits.'],
                        ['outline', 'Generate outline', 'Proposes headings for what is written so far.'],
                    ] as [$action, $label, $note])
                        <li>
                            <button type="button" class="list-row"
                                    @click="Livewire.dispatchTo('documents.ai-assistant', 'ai-action', { action: '{{ $action }}' })">
                                <span class="list-key">
                                    {{ $label }}
                                    <span class="list-sub">{{ $note }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- The assistant itself. It used to be a floating pill plus a
                 second fixed panel over the desk; it is the bottom of this
                 tab now. --}}
            @livewire('documents.ai-chat', ['document' => $document], key('dock-ai-chat'))
        @else
            <div class="empty">
                <p class="empty-line">The assistant works inside a document, alongside what you are writing.</p>
                <a href="{{ route('documents.index') }}" class="btn btn-primary">Open a document</a>
            </div>
        @endif
    </div>

    <div class="dock-body" role="tabpanel" id="dock-panel-data"
         aria-labelledby="dock-tab-data" tabindex="0" x-show="tab === 'data'" x-cloak>
        @if ($document)
            <section class="dock-section">
                <div class="dock-section-head">
                    <h2 class="section-title">Record</h2>
                </div>
                <ul class="list">
                    <li class="list-row">
                        <span class="list-key">Version</span>
                        <span class="list-val">
                            <x-shell.figure :value="$document->version" prefix="v" label="Version" />
                        </span>
                    </li>
                    <li class="list-row">
                        <span class="list-key">Words</span>
                        <span class="list-val">
                            <x-shell.figure :value="$document->word_count ?? 0" label="Words" />
                        </span>
                    </li>
                    <li class="list-row">
                        <span class="list-key">Style</span>
                        <span class="list-val">{{ $document->style_key ?: 'report' }}</span>
                    </li>
                    <li class="list-row">
                        <span class="list-key">Edited</span>
                        <span class="list-val">{{ $document->updated_at?->diffForHumans() }}</span>
                    </li>
                    <li class="list-row">
                        <span class="list-key">Owner</span>
                        <span class="list-val">{{ $document->owner?->name ?? 'Unknown' }}</span>
                    </li>
                </ul>
            </section>

            <section class="dock-section">
                <div class="dock-section-head">
                    <h2 class="section-title">Reach</h2>
                </div>
                <ul class="list">
                    <li class="list-row">
                        <span class="list-key">
                            {{ $document->is_public ? 'Anyone with the link can read it' : 'Named people only' }}
                        </span>
                        <x-shell.status-word :tone="$document->is_public ? 'good' : 'idle'"
                                      :word="$document->is_public ? 'Public' : 'Private'" />
                    </li>
                    <li class="list-row">
                        <span class="list-key">Collaborators</span>
                        <span class="list-val">
                            <x-shell.figure :value="$document->collaborators()->count()" label="Collaborators" />
                        </span>
                    </li>
                    <li>
                        <a href="{{ route('documents.share', $document->uuid) }}" class="list-row">
                            <span class="list-key">Manage sharing</span>
                        </a>
                    </li>
                </ul>
            </section>
        @else
            <section class="dock-section">
                <div class="dock-section-head">
                    <h2 class="section-title">Session</h2>
                </div>
                <ul class="list">
                    <li class="list-row">
                        <span class="list-key">Signed in</span>
                        <span class="list-val">{{ $user?->name }}</span>
                    </li>
                    <li class="list-row">
                        <span class="list-key">Workspace</span>
                        <span class="list-val">{{ $user?->currentTeam->name ?? 'Personal' }}</span>
                    </li>
                </ul>
            </section>
            <div class="empty">
                <p class="empty-line">Open a document and its record shows up here.</p>
                <a href="{{ route('documents.index') }}" class="btn">Browse documents</a>
            </div>
        @endif
    </div>
</aside>
