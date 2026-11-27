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
            <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
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
                    <input id="set-title" wire:model="title" type="text" class="field" @error('title') aria-invalid="true" aria-describedby="set-title-error" @enderror />
                    <p class="field-error" id="set-title-error" role="alert">@error('title'){{ $message }}@enderror</p>
                </div>

                <p class="list-sub">
                    Publishing a read-only link is on the
                    <a href="{{ route('documents.share', $document->uuid) }}" class="link">Share page</a>.
                </p>

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
                    <select id="set-folder" wire:model="folderId" class="field u-grow" @error('folderId') aria-invalid="true" aria-describedby="set-folder-error" @enderror>
                        <option value="">The root of this workspace</option>
                        @foreach ($this->availableFolders as $folder)
                            <option value="{{ $folder['id'] }}">{{ $folder['label'] }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn" wire:click="moveToFolder">Move it</button>
                </div>
                <p class="field-error" id="set-folder-error" role="alert">@error('folderId'){{ $message }}@enderror</p>
            </div>

            <div class="field-row">
                <span class="field-label" id="set-tags-label">Tags</span>
                <div class="toolbar u-mb-3" role="group" aria-labelledby="set-tags-label">
                    @forelse ($this->tags as $tag)
                        <span class="tag">
                            {{ $tag->name }}
                            <button type="button" class="link" wire:click="removeTag({{ $tag->id }})"
                                    aria-label="Remove the tag {{ $tag->name }}">Remove</button>
                        </span>
                    @empty
                        <span class="list-sub">No tags on this document yet.</span>
                    @endforelse
                </div>
                <form wire:submit="addTag" class="toolbar">
                    <label class="sr-only" for="set-new-tag">New tag</label>
                    <input id="set-new-tag" wire:model="newTagName" type="text" class="field u-grow"
                           placeholder="A word you will search for later" />
                    <button type="submit" class="btn">Add the tag</button>
                </form>
            </div>
        </div>
    </section>

    @if ($brandName !== null)
        {{-- The company brand (R2-3c): one switch per document. --}}
        <section class="panel" aria-labelledby="set-brand">
            <div class="panel-head">
                <h2 class="section-title" id="set-brand">Company brand</h2>
            </div>
            <div class="panel-body stack">
                <p class="empty-line">{{ $brandName }}’s colours and fonts, and its letterhead, footer and disclaimer on PDF, print and Word. <a href="{{ route('brand.index') }}">See the brand</a></p>
                <div class="toolbar">
                    @if ($usesBrand)
                        <x-shell.status-word tone="good" word="On" />
                        <button type="button" class="btn" wire:click="setBrand(false)">Stop using the company brand</button>
                    @else
                        <x-shell.status-word word="Off" />
                        <button type="button" class="btn" wire:click="setBrand(true)">Use the company brand</button>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <section class="panel" aria-labelledby="set-page">
        <div class="panel-head">
            <h2 class="section-title" id="set-page">Page setup</h2>
            <span class="micro">Used by print and PDF export</span>
        </div>
        <div class="panel-body">
            <form wire:submit="savePageSetup" class="stack">
                <div class="grid-2">
                    <div class="field-row u-mt-0">
                        <label class="field-label" for="set-size">Paper size</label>
                        <select id="set-size" wire:model="pageSize" class="field" @error('pageSize') aria-invalid="true" aria-describedby="set-size-error" @enderror>
                            <option value="A4">A4</option>
                            <option value="A3">A3</option>
                            <option value="Letter">Letter</option>
                        </select>
                        <p class="field-error" id="set-size-error" role="alert">@error('pageSize'){{ $message }}@enderror</p>
                    </div>
                    <div class="field-row u-mt-0">
                        <label class="field-label" for="set-orientation">Orientation</label>
                        <select id="set-orientation" wire:model="orientation" class="field" @error('orientation') aria-invalid="true" aria-describedby="set-orientation-error" @enderror>
                            <option value="portrait">Portrait</option>
                            <option value="landscape">Landscape</option>
                        </select>
                        <p class="field-error" id="set-orientation-error" role="alert">@error('orientation'){{ $message }}@enderror</p>
                    </div>
                </div>

                <div class="grid-4">
                    @foreach ([
                        ['marginTop', 'Margin top', '25mm'],
                        ['marginRight', 'Margin right', '20mm'],
                        ['marginBottom', 'Margin bottom', '25mm'],
                        ['marginLeft', 'Margin left', '20mm'],
                    ] as [$model, $label, $placeholder])
                        <div class="field-row u-mt-0">
                            <label class="field-label" for="set-{{ $model }}">{{ $label }}</label>
                            <input id="set-{{ $model }}" wire:model="{{ $model }}" type="text" class="field field-mono"
                                   placeholder="{{ $placeholder }}" @error($model) aria-invalid="true" aria-describedby="set-{{ $model }}-error" @enderror />
                            <p class="field-error" id="set-{{ $model }}-error" role="alert">@error($model){{ $message }}@enderror</p>
                        </div>
                    @endforeach
                </div>

                <div class="field-row">
                    <label class="field-label" for="set-header">Running header</label>
                    <input id="set-header" wire:model="header" type="text" class="field field-mono" placeholder="@{{ title }}" @error('header') aria-invalid="true" aria-describedby="set-header-error" @enderror />
                    <p class="field-error" id="set-header-error" role="alert">@error('header'){{ $message }}@enderror</p>
                </div>

                <div class="field-row">
                    <label class="field-label" for="set-footer">Running footer</label>
                    <input id="set-footer" wire:model="footer" type="text" class="field field-mono" placeholder="Page @{{ page }} of @{{ pages }}" @error('footer') aria-invalid="true" aria-describedby="set-footer-error" @enderror />
                    <p class="field-error" id="set-footer-error" role="alert">@error('footer'){{ $message }}@enderror</p>
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
                    <input id="set-transfer" wire:model="transferEmail" type="email" class="field" placeholder="name@example.com" @error('transferEmail') aria-invalid="true" aria-describedby="set-transfer-error" @enderror />
                    <p class="field-error" id="set-transfer-error" role="alert">@error('transferEmail'){{ $message }}@enderror</p>
                    <p class="field-hint">Someone in {{ $document->team?->name ?? 'this company' }}. They become the owner; you keep editing it.</p>
                </div>
                <button type="submit" class="btn"
                        wire:confirm="Hand this document to someone else? They become its owner and can delete it.">Transfer ownership</button>
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
            <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span>
            <h2 class="section-title u-grow" id="set-delete">Deleting this document</h2>
        </div>
        <div class="panel-body">
            @if (! $showDeleteConfirm)
                <p class="empty-line">Deleting moves the document and every version of it to the <a href="{{ route('documents.bin') }}">Bin</a>, where you or a company admin can restore it.</p>
                <button type="button" class="btn btn-danger" wire:click="$set('showDeleteConfirm', true)">Delete this document</button>
            @else
                <p class="empty-line">Move {{ $document->title }} and all of its history to the Bin?</p>
                <div class="toolbar">
                    <button type="button" class="btn btn-danger" wire:click="delete">
                        <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span> Yes, delete it
                    </button>
                    <button type="button" class="btn" wire:click="$set('showDeleteConfirm', false)">Keep it</button>
                </div>
            @endif
        </div>
    </section>
</div>
