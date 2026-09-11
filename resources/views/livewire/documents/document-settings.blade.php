<div class="page page-narrow">
    <div class="page-head">
        <div>
            <h1 class="page-title">Document settings</h1>
            <p class="page-lede">Naming, filing, page setup and ownership for {{ $document->title }}.</p>
        </div>
        <a href="{{ route('documents.edit', $document->uuid) }}" class="btn">Back to the editor</a>
    </div>

    @if (session('status'))
        <p class="note" role="status">
            <span class="lamp lamp-good" aria-hidden="true"></span>
            {{ session('status') }}
        </p>
    @endif

    <section class="panel" aria-labelledby="set-general">
        <div class="panel-head">
            <h2 class="section-title" id="set-general">General</h2>
        </div>
        <div class="panel-body">
            <form wire:submit="save" class="stack">
                <div class="field-row">
                    <label class="field-label" for="set-title">Title</label>
                    <input id="set-title" wire:model="title" type="text" class="field" />
                    @error('title')
                        <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                    @enderror
                </div>

                <label class="field-check" for="is_public">
                    <input wire:model="isPublic" type="checkbox" id="is_public" class="field-box" />
                    <span>
                        Publish a read-only link
                        <span class="ledger-sub">Anyone holding the link can read the document.</span>
                    </span>
                </label>

                <button type="submit" class="btn btn-primary">Save the settings</button>
            </form>
        </div>
    </section>

    <section class="panel" aria-labelledby="set-filing">
        <div class="panel-head">
            <h2 class="section-title" id="set-filing">Filing</h2>
        </div>
        <div class="panel-body">
            <div class="field-row">
                <label class="field-label" for="set-folder">Folder</label>
                <div class="toolbar">
                    <select id="set-folder" wire:model="folderId" class="field" style="flex:1 1 auto">
                        <option value="">The root of this workspace</option>
                        @foreach ($this->availableFolders as $folder)
                            <option value="{{ $folder['id'] }}">{{ $folder['label'] }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn" wire:click="moveToFolder">Move it</button>
                </div>
                @error('folderId')
                    <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                @enderror
            </div>

            <div class="field-row">
                <span class="field-label" id="set-tags-label">Tags</span>
                <div class="toolbar" aria-labelledby="set-tags-label" style="margin-bottom:var(--s3)">
                    @forelse ($this->tags as $tag)
                        <span class="tag">
                            {{ $tag->name }}
                            <button type="button" class="link" wire:click="removeTag({{ $tag->id }})"
                                    aria-label="Remove the tag {{ $tag->name }}">Remove</button>
                        </span>
                    @empty
                        <span class="ledger-sub">No tags on this document yet.</span>
                    @endforelse
                </div>
                <form wire:submit="addTag" class="toolbar">
                    <label class="sr-only" for="set-new-tag">New tag</label>
                    <input id="set-new-tag" wire:model="newTagName" type="text" class="field" style="flex:1 1 auto"
                           placeholder="A word you will search for later" />
                    <button type="submit" class="btn">Add the tag</button>
                </form>
            </div>
        </div>
    </section>

    <section class="panel" aria-labelledby="set-page">
        <div class="panel-head">
            <h2 class="section-title" id="set-page">Page setup</h2>
            <span class="readout">Used by print and PDF export</span>
        </div>
        <div class="panel-body">
            <form wire:submit="savePageSetup" class="stack">
                <div class="grid-2">
                    <div class="field-row" style="margin-top:0">
                        <label class="field-label" for="set-size">Paper size</label>
                        <select id="set-size" wire:model="pageSize" class="field">
                            <option value="A4">A4</option>
                            <option value="A3">A3</option>
                            <option value="Letter">Letter</option>
                        </select>
                        @error('pageSize')
                            <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                        @enderror
                    </div>
                    <div class="field-row" style="margin-top:0">
                        <label class="field-label" for="set-orientation">Orientation</label>
                        <select id="set-orientation" wire:model="orientation" class="field">
                            <option value="portrait">Portrait</option>
                            <option value="landscape">Landscape</option>
                        </select>
                        @error('orientation')
                            <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid-4">
                    @foreach ([
                        ['marginTop', 'Margin top', '25mm'],
                        ['marginRight', 'Margin right', '20mm'],
                        ['marginBottom', 'Margin bottom', '25mm'],
                        ['marginLeft', 'Margin left', '20mm'],
                    ] as [$model, $label, $placeholder])
                        <div class="field-row" style="margin-top:0">
                            <label class="field-label" for="set-{{ $model }}">{{ $label }}</label>
                            <input id="set-{{ $model }}" wire:model="{{ $model }}" type="text" class="field field-mono"
                                   placeholder="{{ $placeholder }}" />
                            @error($model)
                                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>

                <div class="field-row">
                    <label class="field-label" for="set-header">Running header</label>
                    <input id="set-header" wire:model="header" type="text" class="field field-mono" placeholder="@{{ title }}" />
                    @error('header')
                        <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                    @enderror
                </div>

                <div class="field-row">
                    <label class="field-label" for="set-footer">Running footer</label>
                    <input id="set-footer" wire:model="footer" type="text" class="field field-mono" placeholder="Page @{{ page }} of @{{ pages }}" />
                    @error('footer')
                        <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                    @enderror
                    <p class="field-hint">
                        Takes @{{ title }}, @{{ date }}, @{{ team }}, @{{ page }}, @{{ pages }} and any variable the document defines.
                    </p>
                </div>

                <button type="submit" class="btn btn-primary">Save the page setup</button>
            </form>
        </div>
    </section>

    <section class="panel" aria-labelledby="set-owner">
        <div class="panel-head">
            <h2 class="section-title" id="set-owner">Ownership</h2>
        </div>
        <div class="panel-body">
            <form wire:submit="transferOwnership" class="stack">
                <div class="field-row">
                    <label class="field-label" for="set-transfer">Hand the document to</label>
                    <input id="set-transfer" wire:model="transferEmail" type="email" class="field" placeholder="name@example.com" />
                    @error('transferEmail')
                        <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                    @enderror
                    <p class="field-hint">They become the owner; you keep access as an editor.</p>
                </div>
                <button type="submit" class="btn">Transfer ownership</button>
            </form>
        </div>
    </section>

    <section class="panel" aria-labelledby="set-webhooks">
        <div class="panel-head">
            <h2 class="section-title" id="set-webhooks">Notifications</h2>
        </div>
        <div class="panel-body">
            @livewire('documents.webhook-manager', ['document' => $document], key('webhook-manager'))
        </div>
    </section>

    <section class="panel" aria-labelledby="set-delete">
        <div class="panel-head">
            <span class="lamp lamp-danger" aria-hidden="true"></span>
            <h2 class="section-title" id="set-delete" style="flex:1 1 auto">Deleting this document</h2>
        </div>
        <div class="panel-body">
            @if (! $showDeleteConfirm)
                <p class="empty-line">Deleting removes the document and every version of it. There is no undo.</p>
                <button type="button" class="btn btn-danger" wire:click="$set('showDeleteConfirm', true)">Delete this document</button>
            @else
                <p class="empty-line">Delete {{ $document->title }} and all of its history?</p>
                <div class="toolbar">
                    <button type="button" class="btn btn-danger" wire:click="delete">
                        <span class="lamp lamp-danger" aria-hidden="true"></span> Yes, delete it
                    </button>
                    <button type="button" class="btn" wire:click="$set('showDeleteConfirm', false)">Keep it</button>
                </div>
            @endif
        </div>
    </section>
</div>
