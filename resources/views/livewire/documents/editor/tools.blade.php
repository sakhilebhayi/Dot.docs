{{-- Style, page view, pages, the command palette and filing. One row on a desktop (the wrapper is `display:
     contents`); on a phone they sit in a sheet opened by the Tools button, so the title keeps a full-width row of its own
     and editing starts deliberately (title row, tools sheet, then the paper). --}}
<div class="doc-bar-tools" id="doc-bar-tools" :data-open="toolsOpen ? '' : null"
     @keydown.escape.window="toolsOpen = false">
    <div class="doc-bar-tools-head">
        <span class="doc-bar-tools-title">Document tools</span>
        <button type="button" class="tool" @click="toolsOpen = false">Done</button>
    </div>
<label class="sr-only" for="doc-style-picker">Document style</label>
<select id="doc-style-picker" wire:change="setStyle($event.target.value)" class="tool-select"
        @disabled(! $canEdit) @error('style') aria-invalid="true" aria-describedby="doc-style-error" @enderror>
    @foreach (\App\Styles\StyleEngine::systemKeys() as $styleKey)
        <option value="{{ $styleKey }}" @selected($document->style_key === $styleKey)>{{ ucfirst($styleKey) }}</option>
    @endforeach
</select>
@error('style')
    <span class="field-error" id="doc-style-error" role="alert">{{ $message }}</span>
@enderror

<label class="sr-only" for="doc-view-mode">Page view</label>
<select id="doc-view-mode" class="tool-select"
        x-model="viewMode" @change="window.DotDoc.pagination.setMode(viewMode)">
    <option value="continuous">Continuous</option>
    <option value="single">Single page</option>
    <option value="multi-page">Multi-page</option>
    <option value="focus">Focus</option>
    <option value="print-preview">Print preview</option>
</select>

<button type="button" class="tool tool-mono" aria-pressed="false"
        x-bind:aria-pressed="thumbnailsOpen ? 'true' : 'false'"
        @click="thumbnailsOpen = !thumbnailsOpen; if (thumbnailsOpen) $nextTick(() => window.DotDoc.pagination.refreshThumbnails())">Pages</button>

{{-- Everything structural — headings, lists, tables, images, callouts,
     columns, breaks, cross-references, exports, the assistant — is in
     the registry, which this button and the `/` menu both list. --}}
<button type="button" class="tool tool-mono doc-bar-palette"
        title="Commands — or type / in the document" aria-keyshortcuts="Meta+K Control+K"
        @click="window.DotDoc.openPalette(ed())"><span class="doc-bar-cmd-label">Commands </span><span data-shortcut="mod+k">Ctrl+K</span></button>

{{-- Where this document is filed in the shared Dot.Files tree.
     A quiet line of text, not a link: the button beside it is the one
     affordance, and it opens the same .sheet folder picker the
     documents index uses for rename. --}}
<span class="micro doc-bar-filed"><span class="sr-only">Filed in: </span>{{ collect($this->locationCrumbs)->map(fn ($crumb) => $crumb->name())->join(' / ') ?: 'Unfiled' }}</span>
@if ($canEdit)
    <button type="button" class="tool tool-mono" x-ref="moveTrigger"
            wire:click="$set('showMoveSheet', true)">Move</button>
@endif
@error('location')
    <span class="field-error" role="alert">{{ $message }}</span>
@enderror

</div>
<button type="button" class="tool doc-bar-toolsbtn" aria-controls="doc-bar-tools"
        :aria-expanded="toolsOpen ? 'true' : 'false'" @click="toolsOpen = !toolsOpen">Tools</button>
