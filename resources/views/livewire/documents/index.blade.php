<div class="page">
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
            <span class="readout" aria-hidden="true">/</span>
            <button type="button" wire:click="openFolder({{ $crumb->id }})" class="btn btn-quiet btn-sm">{{ $crumb->name }}</button>
        @endforeach
    </nav>

    @livewire('documents.template-gallery', key('template-gallery'))

    <section class="panel" aria-labelledby="doc-filters">
        <div class="panel-head">
            <h2 class="section-title" id="doc-filters">Find</h2>
            <span class="readout">
                <x-shell.figure :value="$this->documents->total()" :width="4" label="Documents listed" />
                &nbsp;listed
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
            <ul class="ledger">
                @foreach ($this->subfolders as $folder)
                    <li class="ledger-row">
                        <button type="button" wire:click="openFolder({{ $folder->id }})"
                                class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start">
                            {{ $folder->name }}
                        </button>
                        <button type="button" class="btn btn-quiet btn-sm"
                                onclick="const name = prompt('Rename folder', '{{ addslashes($folder->name) }}'); if (name) $wire.renameFolder({{ $folder->id }}, name)">
                            Rename
                        </button>
                        <button type="button" class="btn btn-quiet btn-sm" wire:click="deleteFolder({{ $folder->id }})"
                                wire:confirm="Delete this folder? Documents inside move to the parent folder; nothing is deleted.">
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
            <span wire:loading wire:target="search,filter,filterByTag" class="readout">Filtering</span>
        </div>

        @if ($this->documents->isEmpty() && $this->subfolders->isEmpty())
            <div class="empty">
                <p class="empty-line">No documents here yet.</p>
                <button type="button" class="btn btn-primary" wire:click="$set('showCreateModal', true)">New document</button>
            </div>
        @elseif ($this->documents->isEmpty())
            <div class="empty">
                <p class="empty-line">Nothing matches that search in this folder.</p>
                <button type="button" class="btn" wire:click="$set('search', '')">Clear the search</button>
            </div>
        @else
            <ul class="ledger">
                @foreach ($this->documents as $doc)
                    <li>
                        <a href="{{ route('documents.edit', $doc->uuid) }}" class="ledger-row">
                            <span class="ledger-key">
                                {{ $doc->title ?: 'Untitled' }}
                                <span class="ledger-sub">Edited {{ $doc->updated_at->diffForHumans() }}</span>
                            </span>
                            @if ($doc->is_public)
                                <x-shell.lamp tone="signal" word="Public" style="padding:0;border-right:0" />
                            @endif
                            <span class="ledger-val">
                                <x-shell.figure :value="$doc->version" :width="4" prefix="v" label="Version" />
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="panel-head" style="border-bottom:0;border-top:1px solid var(--rule)">
                @if ($this->documents->hasMorePages())
                    <div x-data
                         x-init="
                            const obs = new IntersectionObserver(entries => { if (entries[0].isIntersecting) $wire.loadMore(); }, { rootMargin: '300px' });
                            obs.observe($el);
                            $wire.$cleanup(() => obs.disconnect());
                         "></div>
                    <span class="readout" wire:loading wire:target="loadMore">Loading more</span>
                    <button type="button" class="btn btn-sm" wire:click="loadMore">Load more</button>
                @else
                    <span class="readout">All of them are listed</span>
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
                                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
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

    @if ($showFolderModal)
        <div class="scrim" wire:click.self="$set('showFolderModal', false)" role="dialog" aria-modal="true" aria-labelledby="new-folder-title">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="new-folder-title">
                        New folder @if ($this->currentFolder) in {{ $this->currentFolder->name }} @endif
                    </h2>
                </div>
                <form wire:submit="createFolder">
                    <div class="sheet-body">
                        <div class="field-row">
                            <label class="field-label" for="new-folder-name">Folder name</label>
                            <input id="new-folder-name" wire:model="newFolderName" type="text" class="field" autofocus
                                   placeholder="What goes in it?" />
                            @error('newFolderName')
                                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
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
