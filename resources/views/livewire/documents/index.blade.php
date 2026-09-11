<div class="page" x-data="{ renameTrigger: null }">
    <div class="page-head">
        <div>
            <h1 class="page-title">Documents</h1>
            <p class="page-lede">Folders and documents in {{ auth()->user()->currentTeam->name ?? 'your personal space' }}.</p>
        </div>
        <div class="toolbar">
            <button type="button" class="btn" wire:click="$set('showFolderModal', true)">New folder</button>
            <button type="button" class="btn" @click="$dispatch('open-template-gallery')">From a template</button>
            <button type="button" class="btn btn-primary" wire:click="$set('showCreateModal', true)">New document</button>
        </div>
    </div>

    {{-- Breadcrumbs: where in the tree this listing is standing. --}}
    <nav class="toolbar" aria-label="Folder path" style="margin-bottom:var(--s4)">
        <button type="button" wire:click="openFolder(null)"
                class="btn btn-quiet btn-sm {{ ! $folderId ? 'is-current' : '' }}">All documents</button>
        @foreach ($this->breadcrumbs as $crumb)
            <span class="micro" aria-hidden="true">/</span>
            <button type="button" wire:click="openFolder({{ $crumb->id }})" class="btn btn-quiet btn-sm">{{ $crumb->name() }}</button>
        @endforeach
    </nav>

    @livewire('documents.template-gallery', key('template-gallery'))

    <section class="panel" aria-labelledby="doc-filters">
        <div class="panel-head">
            <h2 class="section-title" id="doc-filters">Find</h2>
            <span class="micro">
                <x-shell.figure :value="$this->documents->total()" />&nbsp;listed
            </span>
        </div>
        <div class="panel-body">
            <div class="field-row">
                <label class="field-label" for="doc-search">Search titles and text</label>
                <input id="doc-search" wire:model.live.debounce.300ms="search" type="search" class="field"
                       placeholder="A word you remember writing" />
            </div>

            <div class="field-row">
                <span class="field-label" id="doc-scope">Scope</span>
                <div class="toolbar" role="group" aria-labelledby="doc-scope">
                    @foreach (['all' => 'All', 'mine' => 'Mine', 'shared' => 'Shared', 'team' => 'Team'] as $key => $label)
                        <button type="button" class="tag" wire:click="$set('filter', '{{ $key }}')"
                                aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($this->availableTags->isNotEmpty())
                <div class="field-row">
                    <span class="field-label" id="doc-tags">Tags</span>
                    <div class="toolbar" role="group" aria-labelledby="doc-tags">
                        <button type="button" class="tag" wire:click="filterByTag(null)"
                                aria-pressed="{{ ! $tagId ? 'true' : 'false' }}">Any</button>
                        @foreach ($this->availableTags as $tag)
                            <button type="button" class="tag" wire:click="filterByTag({{ $tag->id }})"
                                    aria-pressed="{{ $tagId === $tag->id ? 'true' : 'false' }}">{{ $tag->name }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>

    @if ($this->subfolders->isNotEmpty() && ! $search && ! $tagId)
        <section class="panel" aria-labelledby="doc-folders">
            <div class="panel-head">
                <h2 class="section-title" id="doc-folders">Folders</h2>
            </div>
            @error('folder')
                <div class="panel-body">
                    <p class="field-error">{{ $message }}</p>
                </div>
            @enderror
            <ul class="list">
                @foreach ($this->subfolders as $folder)
                    <li class="list-row">
                        <button type="button" wire:click="openFolder({{ $folder->id }})"
                                class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                            {{ $folder->name() }}
                        </button>
                        <button type="button" class="btn btn-quiet btn-sm"
                                @click="renameTrigger = $el"
                                wire:click="startRenamingFolder({{ $folder->id }})">
                            Rename
                        </button>
                        <button type="button" class="btn btn-quiet btn-sm" wire:click="deleteFolder({{ $folder->id }})"
                                wire:confirm="Delete this folder? It has to be empty first — nothing inside is ever deleted with it.">
                            Delete
                        </button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="panel" aria-labelledby="doc-list">
        <div class="panel-head">
            <h2 class="section-title" id="doc-list">Documents</h2>
            <span wire:loading wire:target="search,filter,filterByTag" class="micro">Filtering</span>
        </div>

        {{-- The "nothing matched" branch is tested FIRST: with no subfolders in
             the current node, a search that matches nothing satisfied the
             empty-everything condition too, and the page answered a search with
             "Start with an idea." --}}
        @if ($this->documents->isEmpty() && ($search !== '' || $tagId))
            <div class="empty">
                <p class="empty-line">Nothing here matches "{{ $search }}".</p>
                <div class="empty-actions">
                    <button type="button" class="btn" wire:click="$set('search', '')">Clear the search</button>
                </div>
            </div>
        @elseif ($this->documents->isEmpty() && $this->subfolders->isEmpty())
            {{-- One sentence, then what to do about it. No illustration, no
                 "No documents found." A third action, "Create with AI", is
                 deliberately absent: nothing in this application generates a
                 whole document from a prompt yet (App\Livewire\Documents\
                 AiAssistant works on a document that already exists), and an
                 action that does nothing is worse than an action that is not
                 there. It belongs to whichever task ships that capability. --}}
            <div class="empty">
                <p class="empty-line">Start with an idea.</p>
                <div class="empty-actions">
                    <button type="button" class="btn btn-primary" wire:click="$set('showCreateModal', true)">Blank document</button>
                    <button type="button" class="btn" @click="$dispatch('open-template-gallery')">Use a template</button>
                </div>
            </div>
        @else
            <ul class="list">
                @foreach ($this->documents as $doc)
                    <li>
                        <a href="{{ route('documents.edit', $doc->uuid) }}" class="list-row">
                            <span class="list-key">
                                {{ $doc->title ?: 'Untitled' }}
                                <span class="list-sub">Edited {{ $doc->updated_at->diffForHumans() }}</span>
                            </span>
                            @if ($doc->is_public)
                                <x-shell.status-word tone="good" word="Public" />
                            @endif
                            <span class="list-val">
                                <x-shell.figure :value="$doc->version" prefix="v" label="Version" />
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="panel-foot">
                @if ($this->documents->hasMorePages())
                    <div x-data
                         x-init="
                            const obs = new IntersectionObserver(entries => { if (entries[0].isIntersecting) $wire.loadMore(); }, { rootMargin: '300px' });
                            obs.observe($el);
                            $wire.$cleanup(() => obs.disconnect());
                         "></div>
                    <span class="micro" wire:loading wire:target="loadMore">Loading more</span>
                    <button type="button" class="btn btn-sm" wire:click="loadMore">Load more</button>
                @else
                    <span class="micro">All of them are listed</span>
                @endif
            </div>
        @endif
    </section>

    {{-- The gallery opens on ?gallery=1 at mount (see TemplateGallery::mount)
         and on this window event from the "From a template" button. --}}
    <div x-data @open-template-gallery.window="Livewire.dispatchTo('documents.template-gallery', 'open')"></div>

    @if ($showCreateModal)
        <div class="scrim" wire:click.self="$set('showCreateModal', false)" role="dialog" aria-modal="true" aria-labelledby="new-doc-title">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="new-doc-title">New document</h2>
                </div>
                <form wire:submit="createDocument">
                    <div class="sheet-body">
                        <div class="field-row">
                            <label class="field-label" for="new-doc-name">Title</label>
                            <input id="new-doc-name" wire:model="newTitle" type="text" class="field" autofocus
                                   placeholder="What is it about?" />
                            @error('newTitle')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="sheet-foot">
                        <button type="button" class="btn" wire:click="$set('showCreateModal', false)">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create the document</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($renamingFolderId)
        <div class="scrim" wire:click.self="cancelRenamingFolder" role="dialog" aria-modal="true" aria-labelledby="rename-folder-title"
             x-on:keydown.escape.window="$wire.cancelRenamingFolder(); renameTrigger && renameTrigger.focus()">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="rename-folder-title">Rename the folder</h2>
                </div>
                <form wire:submit="renameFolder">
                    <div class="sheet-body">
                        <div class="field-row">
                            <label class="field-label" for="rename-folder-name">Folder name</label>
                            <input id="rename-folder-name" wire:model="renameFolderName" type="text" class="field" autofocus />
                            @error('renameFolderName')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="sheet-foot">
                        <button type="button" class="btn" wire:click="cancelRenamingFolder">Cancel</button>
                        <button type="submit" class="btn btn-primary">Rename it</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showFolderModal)
        <div class="scrim" wire:click.self="$set('showFolderModal', false)" role="dialog" aria-modal="true" aria-labelledby="new-folder-title">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="new-folder-title">
                        New folder @if ($this->currentFolder) in {{ $this->currentFolder->name() }} @endif
                    </h2>
                </div>
                <form wire:submit="createFolder">
                    <div class="sheet-body">
                        <div class="field-row">
                            <label class="field-label" for="new-folder-name">Folder name</label>
                            <input id="new-folder-name" wire:model="newFolderName" type="text" class="field" autofocus
                                   placeholder="What goes in it?" />
                            @error('newFolderName')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="sheet-foot">
                        <button type="button" class="btn" wire:click="$set('showFolderModal', false)">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create the folder</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
