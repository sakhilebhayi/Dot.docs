<div>
    {{-- Command palette --}}
    @if ($showPalette)
        <div class="scrim" style="align-items:flex-start;padding-top:12vh" role="dialog" aria-modal="true"
             aria-label="Assistant commands" x-data x-init="$el.querySelector('input').focus()">
            <div class="sheet" wire:click.outside="closePalette">
                <div class="sheet-head">
                    <label class="sr-only" for="ai-command">Command</label>
                    <input id="ai-command" wire:model="command" wire:keydown.enter="runCommand"
                           wire:keydown.escape="closePalette" type="text" class="field"
                           placeholder="/summarize, /grammar, /tone formal, /translate French" />
                    @if ($loading)
                        <span class="toolbar" aria-live="polite">
                            <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
                            <span class="micro">Working</span>
                        </span>
                    @else
                        <button type="button" class="btn btn-primary" wire:click="runCommand">Run</button>
                    @endif
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
        <div class="scrim" role="dialog" aria-modal="true" aria-labelledby="ai-result-title"
             x-data x-trap.inert.noscroll="true">
            <div class="sheet sheet-wide">
                <div class="sheet-head">
                    <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
                    <h2 class="h-panel" id="ai-result-title">In marker — {{ ucfirst($action) }}</h2>
                </div>
                <div class="sheet-body ink-marker">
                    {!! $result !!}
                </div>
                <div class="sheet-foot">
                    <button type="button" class="btn" wire:click="dismissResult">Drop it</button>
                    <button type="button" class="btn btn-primary" wire:click="applyResult">Accept it into the document</button>
                </div>
            </div>
        </div>
    @endif
</div>
