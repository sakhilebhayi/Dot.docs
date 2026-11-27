<div class="editor-row">
    {{-- wire:ignore keeps Livewire's DOM morph out of the ProseMirror
         subtree, which it did not render and must not diff. data-outline
         seeds the numbering before the first save round trip. The outer
         wire:ignore on .editor-main protects the pagination DOM siblings
         of #doc-paper (the Multi-Page grid, the Print Preview iframe)
         that Livewire's own render never produced - without it, the
         next morph would strip them as extra nodes. --}}
    <div class="editor-main" wire:ignore>
        <div id="doc-paper" x-ref="editorEl" wire:ignore class="canvas" data-outline="{{ json_encode($outline) }}"></div>
    </div>

    {{-- Populated by the "Pages" button's @click (editor/tools.blade.php) the moment the
         panel opens (pagination.refreshThumbnails()), and kept current
         after that by every repagination pass while it stays open
         (see refreshRailIfVisible() in pagination/index.js) - not by
         any init hook here, since this div is already in the DOM
         (just hidden) when Alpine initialises, well before the writer
         ever opens it. --}}
    <div class="editor-thumbnails" x-show="thumbnailsOpen" x-cloak
         role="region" aria-label="Page thumbnails">
        <div class="dotdoc-thumbnail-rail" wire:ignore></div>
    </div>

    @if ($commentSidebarOpen)
        <div class="editor-side">
            @livewire('documents.comment-thread', ['document' => $document], key('comment-thread'))
        </div>
    @endif
</div>
