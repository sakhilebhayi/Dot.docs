{{-- Everything that acts on the whole document, in one menu, so the
     row never has to reflow. --}}
<div class="menu doc-bar-end" x-data="{ open: false }"
     x-on:keydown.escape.window="if (open) { open = false; $refs.moreBtn.focus() }">
    <button type="button" class="tool tool-mono" x-ref="moreBtn" @click="open = !open"
            :aria-expanded="open ? 'true' : 'false'">More</button>

    <div class="menu-list menu-list-wide" x-show="open" @click.outside="open = false" x-cloak>
        <button type="button" data-shell-expand="dock"
                @click="$dispatch('open-ai-palette'); open = false">
            Ask the assistant
            <span class="micro" data-shortcut="mod+shift+k">Ctrl+Shift+K</span>
        </button>

        {{-- "Suggest instead of editing" is hidden until tracked suggestions
             exist: the toggle changed nothing, edits went straight in
             (EA-4 / F-01, owner decision 2026-11-26). --}}

        {{-- No `data-shell-expand` here, deliberately: comments render
             in `.editor-side`, beside the paper, NOT in the dock.
             Revealing a panel is one-way by design, so pointing this at
             the dock took ~340px of canvas width in either direction
             with no way back. --}}
        <button type="button" wire:click="toggleCommentSidebar"
                aria-pressed="{{ $commentSidebarOpen ? 'true' : 'false' }}">
            {{ $commentSidebarOpen ? 'Hide comments' : 'Show comments' }}
        </button>

        @if ($canEdit)
            <button type="button" @click="ed().chain().focus().undo().run(); open = false">
                Undo
                <span class="micro" data-shortcut="mod+z">Ctrl+Z</span>
            </button>
            <button type="button" @click="ed().chain().focus().redo().run(); open = false">
                Redo
                <span class="micro" data-shortcut="mod+shift+z">Ctrl+Shift+Z</span>
            </button>

            <button type="button"
                    :class="ed()?.isActive('code') ? 'is-on' : ''"
                    @click="ed().chain().focus().toggleCode().run(); open = false">Inline code</button>

            <div x-data="voiceTyping" x-init="init()">
                <button type="button" x-show="supported" @click="toggle()"
                        :aria-pressed="listening ? 'true' : 'false'"
                        x-text="listening ? 'Stop voice typing' : 'Start voice typing'">Start voice typing</button>
            </div>
        @endif

        <span class="menu-label">Export</span>
        <a href="{{ route('documents.export', [$document->uuid, 'pdf']) }}">PDF</a>
        <a href="{{ route('documents.export', [$document->uuid, 'word']) }}">Word (.docx)</a>
        <a href="{{ route('documents.export', [$document->uuid, 'html']) }}">HTML</a>
        <a href="{{ route('documents.export', [$document->uuid, 'markdown']) }}">Markdown</a>

        {{-- The same render, filed beside the document in the
             shared tree instead of downloaded. --}}
        <span class="menu-label">Save to Dot.Files</span>
        {{-- A bare <form>/<button>, NOT .menu-form/.btn: those are
             for the import picker, and their centred full-width
             button breaks the menu's row rhythm beside the export
             links above. `.menu-list button` already styles this. --}}
        @foreach (['pdf' => 'PDF', 'word' => 'Word (.docx)', 'html' => 'HTML', 'markdown' => 'Markdown'] as $format => $label)
            <form action="{{ route('documents.export.save-to-files', [$document->uuid, $format]) }}" method="POST">
                @csrf
                <button type="submit">{{ $label }}</button>
            </form>
        @endforeach

        @if ($canEdit)
            <span class="menu-label">Import</span>
            <form action="{{ route('documents.import', $document->uuid) }}" method="POST"
                  enctype="multipart/form-data" class="menu-form"
                  x-on:submit="if (! confirm('Replace this document with the imported file? What is there now is kept in version history.')) $event.preventDefault()">
                @csrf
                <label class="field-label" for="doc-import">A .docx, .md or .txt file</label>
                <input id="doc-import" type="file" name="file" accept=".docx,.md,.markdown,.txt" class="field" />
                <button type="submit" class="btn btn-primary">Import it</button>
            </form>

            <button type="button" @click="$dispatch('open-save-as-template'); open = false"
                    title="Save this document as a reusable template">Save as a template</button>
        @endif
    </div>
</div>
