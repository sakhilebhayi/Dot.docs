{{--
    One folder of the shared Dot.Files tree at a time.

    Every action is a button or a link: opening a folder navigates, Move is a
    sheet-based folder picker, and there is no drag-and-drop to need a
    keyboard fallback for. The two sheets follow the same APG pattern the
    documents index established for rename - Escape closes and returns focus
    to the control that opened it, which is what `moveTrigger` holds.
--}}
<div x-data="{ sheetTrigger: null }">
    <nav class="toolbar" aria-label="Folder path" style="margin-bottom:var(--s4)">
        @foreach ($this->crumbs as $crumb)
            @if (! $loop->first)
                <span class="readout" aria-hidden="true">/</span>
            @endif
            <button type="button" wire:click="open('{{ $loop->last ? '' : $crumb->uuid }}')"
                    class="btn btn-quiet btn-sm"
                    @if ($loop->last) aria-current="page" @endif>{{ $crumb->name() }}</button>
        @endforeach
    </nav>

    <section class="panel" aria-labelledby="files-here">
        <div class="panel-head">
            <h2 class="section-title" id="files-here">In {{ $this->parent->name() }}</h2>
            <span class="readout">
                <x-shell.figure :value="$this->rows->count()" :width="3" label="Items in this folder" />
                &nbsp;items
            </span>
        </div>

        <div class="panel-body">
            <div class="toolbar" role="group" aria-label="Add to this folder">
                <button type="button" class="btn" wire:click="$set('showFolderSheet', true)">New folder</button>
                <button type="button" class="btn" wire:click="importHere">Import a document</button>
                <form method="POST" action="{{ route('files.upload', $this->parent->uuid) }}"
                      enctype="multipart/form-data" class="toolbar">
                    @csrf
                    <label class="field-label" for="files-upload">Upload a file</label>
                    <input id="files-upload" type="file" name="file" class="field" style="flex:0 1 auto" required />
                    <button type="submit" class="btn">Upload it</button>
                </form>
                <button type="button" class="btn btn-primary" wire:click="$set('showCreateSheet', true)">New document</button>
            </div>

            @error('object')
                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
            @enderror
            @error('file')
                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
            @enderror
        </div>

        @if ($this->rows->isEmpty())
            <div class="empty">
                <p class="empty-line">This folder is empty.</p>
                <button type="button" class="btn btn-primary" wire:click="$set('showCreateSheet', true)">New document</button>
            </div>
        @else
            <ul class="ledger">
                @foreach ($this->rows as $row)
                    <li class="ledger-row" wire:key="node-{{ $row->uuid }}">
                        @if ($row->isFolder())
                            <button type="button" wire:click="open('{{ $row->uuid }}')"
                                    class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                                <span class="ledger-key">{{ $row->name() }}</span>
                            </button>
                            <x-shell.lamp tone="idle" word="Folder" />
                        @elseif ($row->isDocument())
                            <a href="{{ route('documents.edit', $row->objectable->uuid) }}"
                               class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                                <span class="ledger-key">
                                    {{ $row->name() }}
                                    <span class="ledger-sub">Edited {{ $row->objectable->updated_at?->diffForHumans() }}</span>
                                </span>
                            </a>
                            <x-shell.lamp tone="signal" word="Document" />
                            <span class="ledger-val">
                                <x-shell.figure :value="$row->objectable->version" :width="4" prefix="v" label="Version" />
                            </span>
                        @else
                            <a href="{{ $this->fileUrl($row) }}" target="_blank" rel="noopener"
                               class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                                <span class="ledger-key">
                                    {{ $row->name() }}
                                    <span class="ledger-sub">{{ $row->objectable->sizeForHumans() }}</span>
                                </span>
                            </a>
                            <x-shell.lamp tone="good" word="File" />
                        @endif

                        <button type="button" class="btn btn-quiet btn-sm"
                                @click="sheetTrigger = $el"
                                wire:click="startRenaming('{{ $row->uuid }}')">Rename</button>
                        <button type="button" class="btn btn-quiet btn-sm"
                                @click="sheetTrigger = $el"
                                wire:click="startMoving('{{ $row->uuid }}')">Move</button>
                        <button type="button" class="btn btn-quiet btn-sm"
                                wire:click="deleteObject('{{ $row->uuid }}')"
                                wire:confirm="Remove this from the tree? A document goes to the trash and can be restored; a file is deleted.">
                            Delete
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($showCreateSheet)
        <div class="scrim" wire:click.self="$set('showCreateSheet', false)" role="dialog" aria-modal="true"
             aria-labelledby="files-new-doc-title"
             x-on:keydown.escape.window="$wire.set('showCreateSheet', false)">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="files-new-doc-title">New document in {{ $this->parent->name() }}</h2>
                </div>
                <form wire:submit="createDocument">
                    <div class="sheet-body">
                        <div class="field-row">
                            <label class="field-label" for="files-new-doc">Title</label>
                            <input id="files-new-doc" wire:model="newTitle" type="text" class="field" autofocus
                                   placeholder="What is it about?" />
                            @error('newTitle')
                                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="sheet-foot">
                        <button type="button" class="btn" wire:click="$set('showCreateSheet', false)">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create the document</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showFolderSheet)
        <div class="scrim" wire:click.self="$set('showFolderSheet', false)" role="dialog" aria-modal="true"
             aria-labelledby="files-new-folder-title"
             x-on:keydown.escape.window="$wire.set('showFolderSheet', false)">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="files-new-folder-title">New folder in {{ $this->parent->name() }}</h2>
                </div>
                <form wire:submit="createFolder">
                    <div class="sheet-body">
                        <div class="field-row">
                            <label class="field-label" for="files-new-folder">Folder name</label>
                            <input id="files-new-folder" wire:model="newFolderName" type="text" class="field" autofocus
                                   placeholder="What goes in it?" />
                            @error('newFolderName')
                                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="sheet-foot">
                        <button type="button" class="btn" wire:click="$set('showFolderSheet', false)">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create the folder</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($renamingUuid)
        <div class="scrim" wire:click.self="cancelRenaming" role="dialog" aria-modal="true"
             aria-labelledby="files-rename-title"
             x-on:keydown.escape.window="$wire.cancelRenaming(); sheetTrigger && sheetTrigger.focus()">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="files-rename-title">Rename</h2>
                </div>
                <form wire:submit="renameObject">
                    <div class="sheet-body">
                        <div class="field-row">
                            <label class="field-label" for="files-rename">Name</label>
                            <input id="files-rename" wire:model="renameName" type="text" class="field" autofocus />
                            @error('renameName')
                                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="sheet-foot">
                        <button type="button" class="btn" wire:click="cancelRenaming">Cancel</button>
                        <button type="submit" class="btn btn-primary">Rename it</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($movingUuid)
        <div class="scrim" wire:click.self="cancelMoving" role="dialog" aria-modal="true"
             aria-labelledby="files-move-title"
             x-on:keydown.escape.window="$wire.cancelMoving(); sheetTrigger && sheetTrigger.focus()">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="files-move-title">Move it to</h2>
                </div>
                <div class="sheet-body">
                    @error('object')
                        <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                    @enderror
                    <ul class="ledger">
                        @foreach ($this->folderChoices as $choice)
                            <li wire:key="dest-{{ $choice['obj']->uuid }}">
                                <button type="button" class="ledger-row" style="width:100%;text-align:left"
                                        wire:click="moveTo('{{ $choice['obj']->uuid }}')">
                                    <span class="ledger-key">{{ $choice['label'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="sheet-foot">
                    <button type="button" class="btn" wire:click="cancelMoving">Cancel</button>
                </div>
            </div>
        </div>
    @endif
</div>
