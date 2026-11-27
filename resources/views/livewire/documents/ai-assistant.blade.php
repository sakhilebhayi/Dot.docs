<div>
    {{-- Toolbar actions arrive as the ai-action event (handleAction); this is their progress (EA-4 / U-01). --}}
    <p class="note" role="status" wire:loading.flex wire:target="handleAction" style="position:fixed;bottom:var(--s4);right:var(--s4);z-index:90;margin:0">
        <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
        The assistant is working…
    </p>

    {{-- Command palette --}}
    @if ($showPalette)
        <div class="scrim" x-trap.noscroll="true" style="align-items:flex-start;padding-top:12vh" role="dialog" aria-modal="true"
             aria-label="Assistant commands" x-data x-init="$el.querySelector('input').focus()">
            <div class="sheet" wire:click.outside="closePalette">
                <div class="sheet-head">
                    <label class="sr-only" for="ai-command">Command</label>
                    <input id="ai-command" wire:model="command" wire:keydown.enter="runCommand"
                           wire:keydown.escape="closePalette" type="text" class="field"
                           placeholder="/summarize, /grammar, /tone formal, /translate French" />
                    {{-- wire:loading, not $loading: the flag was set and cleared inside one
                         request, so the browser never saw it (EA-4 / U-01). --}}
                    <span class="toolbar" aria-live="polite" wire:loading.flex wire:target="runCommand">
                        <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
                        <span class="micro">Working</span>
                    </span>
                    <button type="button" class="btn btn-primary" wire:click="runCommand" wire:loading.remove wire:target="runCommand">Run</button>
                </div>

                <ul class="list" style="max-height:46vh;overflow-y:auto">
                    @foreach ($commandSuggestions as $cmd => $desc)
                        <li>
                            <button type="button" class="list-row" wire:click="$set('command', '{{ $cmd }}')">
                                <span class="list-key">
                                    <span class="micro">{{ $cmd }}</span>
                                    <span class="list-sub">{{ $desc }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- The result arrives in marker: the assistant's ink is visibly its own
         until a person accepts it into the document. It is a SHEET, not a strip
         fixed over the desk — it asks for a decision, and nothing in this
         product floats except the paper. --}}
    @if ($showResult)
        <div class="scrim" x-on:keydown.escape.stop="$wire.dismissResult()" role="dialog" aria-modal="true" aria-labelledby="ai-result-title"
             x-data x-trap.inert.noscroll="true">
            <div class="sheet sheet-wide">
                <div class="sheet-head">
                    <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
                    <h2 class="h-panel" id="ai-result-title">In marker — {{ ucfirst($action) }}</h2>
                </div>
                @if ($this->isDemonstration())
                    <p class="note" role="note" style="margin:0">
                        <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
                        Demonstration answer: no AI model is connected to {{ config('app.name') }} yet, so this text is a placeholder.
                    </p>
                @endif
                <div class="sheet-body ink-marker">
                    {!! $this->safeResult() !!}
                </div>
                <div class="sheet-foot">
                    <button type="button" class="btn" wire:click="dismissResult">{{ $applyMode === null ? 'Close' : 'Drop it' }}</button>
                    @if ($applyMode !== null)
                        @can('update', $document)
                            <button type="button" class="btn btn-primary" wire:click="applyResult">
                                {{ $applyMode === 'replace' ? 'Replace the document with it' : 'Add it at the end' }}
                            </button>
                        @else
                            <span class="micro">You can read this document but not change it.</span>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
