{{--
    One folder of the shared Dot.Files tree at a time.

    Every action is a button or a link: opening a folder navigates, Move is a
    sheet-based folder picker, and there is no drag-and-drop to need a
    keyboard fallback for. The two sheets follow the same APG pattern the
    documents index established for rename - Escape closes and returns focus
    to the control that opened it, which is what `sheetTrigger` holds (the
    rename and move sheets; the New folder / New document sheets have no
    Escape handler, matching the equivalent dialogs on the documents index).
--}}
<div class="page" x-data="{ sheetTrigger: null }">
    <div class="page-head">
        <div>
            <h1 class="page-title">Files</h1>
            <p class="page-lede">Folders, documents and files in {{ auth()->user()->currentTeam->name ?? 'your personal space' }}.</p>
        </div>
        <a href="{{ route('documents.index') }}" class="btn">The documents list</a>
    </div>

    @if (session('status'))
        <p class="note" role="status">
            <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
            {{ session('status') }}
        </p>
    @endif

    <nav class="toolbar" aria-label="Folder path" style="margin-bottom:var(--s4)">
        @foreach ($this->crumbs as $crumb)
            @if (! $loop->first)
                <span class="micro" aria-hidden="true">/</span>
            @endif
            <button type="button" wire:click="open('{{ $loop->last ? '' : $crumb->uuid }}')"
                    class="btn btn-quiet btn-sm"
                    @if ($loop->last) aria-current="page" @endif>{{ $crumb->name() }}</button>
        @endforeach
    </nav>

    <section class="panel" aria-labelledby="files-here">
        <div class="panel-head">
            <h2 class="section-title" id="files-here">In {{ $this->parent->name() }}</h2>
            <span class="micro">
                <x-shell.figure :value="$this->rows->count()" />&nbsp;items
            </span>
        </div>

        <div class="panel-body">
            <div class="toolbar" role="group" aria-label="Add to this folder">
                <button type="button" class="btn" wire:click="$set('showFolderSheet', true)">New folder</button>
                <button type="button" class="btn" wire:click="importHere">Import a document</button>
                <button type="button" class="btn btn-primary" wire:click="$set('showCreateSheet', true)">New document</button>
            </div>

            {{-- Upload is a plain form, not a Livewire action: the file goes
                 straight to FileUploadController, which authorises the PARENT
                 node. It sits on its own row rather than in the toolbar above
                 because a file input is a field, not a button. --}}
            <form method="POST" action="{{ route('files.upload', $this->parent->uuid) }}"
                  enctype="multipart/form-data" class="field-row">
                @csrf
                <label class="field-label" for="files-upload">Upload a file</label>
                <div class="toolbar">
                    <input id="files-upload" type="file" name="file" class="field" style="flex:1 1 260px;min-width:0" required />
                    <button type="submit" class="btn">Upload it</button>
                </div>
                @error('file')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </form>

            @error('object')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        @if ($this->rows->isEmpty())
            <div class="empty">
                <p class="empty-line">This folder is empty.</p>
                <button type="button" class="btn btn-primary" wire:click="$set('showCreateSheet', true)">New document</button>
            </div>
        @else
            <ul class="list">
                @foreach ($this->rows as $row)
                    <li class="list-row" wire:key="node-{{ $row->uuid }}">
                        @if ($row->isFolder())
                            <button type="button" wire:click="open('{{ $row->uuid }}')"
                                    class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                                <span class="list-key">{{ $row->name() }}</span>
                            </button>
                            <x-shell.status-word tone="idle" word="Folder" />
                        @elseif ($row->isDocument())
                            <a href="{{ route('documents.edit', $row->objectable->uuid) }}"
                               class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                                <span class="list-key">
                                    {{ $row->name() }}
                                    <span class="list-sub">Edited {{ $row->objectable->updated_at?->diffForHumans() }}</span>
                                </span>
                            </a>
                            <x-shell.status-word tone="good" word="Document" />
                            <span class="list-val">
                                <x-shell.figure :value="$row->objectable->version" prefix="v" label="Version" />
                            </span>
                        @else
                            <a href="{{ $this->fileUrl($row) }}" target="_blank" rel="noopener"
                               class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                                <span class="list-key">
                                    {{ $row->name() }}
                                    <span class="list-sub">{{ $row->objectable->sizeForHumans() }}</span>
                                </span>
                            </a>
                            <x-shell.status-word tone="good" word="File" />
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
                                <p class="field-error">{{ $message }}</p>
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
                                <p class="field-error">{{ $message }}</p>
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
                                <p class="field-error">{{ $message }}</p>
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
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                    <ul class="list">
                        @foreach ($this->folderChoices as $choice)
                            <li wire:key="dest-{{ $choice['uuid'] }}">
                                <button type="button" class="list-row" style="width:100%;text-align:left"
                                        wire:click="moveTo('{{ $choice['uuid'] }}')">
                                    <span class="list-key">{{ $choice['label'] }}</span>
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
