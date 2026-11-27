<div
    x-data="@include('livewire.documents.editor.alpine')"
    x-init="init()"
    x-destroy="destroy()"
    @ai-apply.window="applyAiContent($event.detail.mode, $event.detail.content)"
    @suggestion-accepted.window="applySuggestion($event.detail.content, $event.detail.version)"
    @voice-transcript.window="insertVoiceText($event.detail.text)"
    @style-changed.window="document.getElementById('doc-style').textContent = $event.detail.css; adoptVersion($event.detail.version); refreshOutline()"
    @keydown.ctrl.shift.k.window.prevent="$dispatch('open-ai-palette')"
    @keydown.meta.shift.k.window.prevent="$dispatch('open-ai-palette')"
    class="editor"
>
    <style id="doc-style">{!! $styleCss !!}</style>

    {{-- AI Components (outside toolbar, at root level) --}}
    {{-- ai-chat is NOT here: the assistant renders inside the dock's
         Intelligence tab (resources/views/components/shell/dock.blade.php).
         Nothing floats over the desk. --}}
    <livewire:documents.ai-assistant :document="$document" wire:key="ai-assistant" lazy />
    <livewire:documents.save-as-template :document="$document" wire:key="save-as-template" lazy />

    {{-- Listen for Ctrl+K to open AI palette --}}
    <div x-data
         @open-ai-palette.window="Livewire.dispatchTo('documents.ai-assistant', 'open-palette')"
         @open-save-as-template.window="Livewire.dispatchTo('documents.save-as-template', 'open')"
         class="hidden"></div>
    {{-- ── The head of the editor page ──────────────────────────────────
         The persistent bar and, directly under it, the notice bar. One
         element, so the two stay at the top of the canvas TOGETHER where
         the page scrolls under them (below 900px): a notice that asks the
         writer to choose must not scroll away with the paper. --}}
    <div class="doc-head">
        @include('livewire.documents.editor.doc-bar')

        @include('livewire.documents.editor.notices')
    </div>

    @include('livewire.documents.editor.suggestions')

    @include('livewire.documents.editor.canvas')

    @include('livewire.documents.editor.move-sheet')
</div>
