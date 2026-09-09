<div class="page page-narrow">
    <div class="page-head">
        <div>
            <h1 class="page-title">Slash commands</h1>
            <p class="page-lede">
                Your own prompts, listed in the command palette and reachable by typing a slash in any document.
                Write <code class="readout">{content}</code> where the document's text should go.
            </p>
        </div>
        <button type="button" class="btn btn-primary" wire:click="openCreate">New command</button>
    </div>

    @if ($showForm)
        <section class="panel" aria-labelledby="cmd-form">
            <div class="panel-head">
                <h2 class="section-title" id="cmd-form">{{ $editingId ? 'Edit the command' : 'New command' }}</h2>
            </div>
            <div class="panel-body">
                <div class="grid-2">
                    <div class="field-row" style="margin-top:0">
                        <label class="field-label" for="cmd-name">Command name</label>
                        <div class="toolbar" style="gap:0">
                            <span class="readout" style="padding:8px var(--s2);border:1px solid var(--rule);border-right:0">/</span>
                            <input id="cmd-name" wire:model="name" type="text" class="field field-mono"
                                   style="flex:1 1 auto" placeholder="bullet-list" />
                        </div>
                        <p class="field-hint">No spaces — this is what you type after the slash.</p>
                        @error('name')
                            <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field-row" style="margin-top:0">
                        <label class="field-label" for="cmd-desc">Description</label>
                        <input id="cmd-desc" wire:model="description" type="text" class="field"
                               placeholder="What it does, in a few words" />
                        <p class="field-hint">Shown beside the command in the palette.</p>
                        @error('description')
                            <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="field-row">
                    <label class="field-label" for="cmd-prompt">Prompt</label>
                    <textarea id="cmd-prompt" wire:model="promptTemplate" rows="5" class="field field-mono"
                              placeholder="Rewrite the following as a numbered list, keeping each point short:&#10;&#10;{content}"></textarea>
                    <p class="field-hint">Use <code class="readout">{content}</code> to drop the document's text into the prompt.</p>
                    @error('promptTemplate')
                        <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                    @enderror
                </div>

                @if (auth()->user()->currentTeam)
                    <label class="field-check" for="cmd-share">
                        <input id="cmd-share" wire:model="shareWithTeam" type="checkbox" class="field-box" />
                        <span>Share it with {{ auth()->user()->currentTeam->name }}</span>
                    </label>
                @endif

                <div class="toolbar">
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        {{ $editingId ? 'Save the command' : 'Create the command' }}
                    </button>
                    <button type="button" class="btn" wire:click="$set('showForm', false)">Cancel</button>
                </div>
            </div>
        </section>
    @endif

    <section class="panel" aria-labelledby="cmd-list">
        <div class="panel-head">
            <h2 class="section-title" id="cmd-list">Your commands</h2>
            <span class="readout">
                <x-shell.figure :value="$commands->count()" :width="2" label="Commands" />
            </span>
        </div>

        @if ($commands->isEmpty())
            <div class="empty">
                <p class="empty-line">You have not written a command yet.</p>
                <button type="button" class="btn btn-primary" wire:click="openCreate">New command</button>
            </div>
        @else
            <ul class="ledger">
                @foreach ($commands as $cmd)
                    <li class="ledger-row">
                        <span class="ledger-key">
                            <span class="readout">/{{ $cmd->name }}</span>
                            <span class="ledger-sub">
                                {{ $cmd->description ?: Str::limit($cmd->prompt_template, 90) }}
                            </span>
                        </span>
                        @if ($cmd->share_with_team)
                            <span class="ledger-val">Team</span>
                        @endif
                        @if ($cmd->user_id !== auth()->id())
                            <span class="ledger-val">Shared with you</span>
                        @else
                            <button type="button" class="btn btn-sm" wire:click="editCommand({{ $cmd->id }})">Edit</button>
                            <button type="button" class="btn btn-sm" wire:click="deleteCommand({{ $cmd->id }})"
                                    wire:confirm="Delete /{{ $cmd->name }}?">Delete</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
