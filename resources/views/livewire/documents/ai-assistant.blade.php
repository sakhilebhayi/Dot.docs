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
                            <span class="lamp lamp-signal" aria-hidden="true"></span>
                            <span class="readout">Working</span>
                        </span>
                    @else
                        <button type="button" class="btn btn-primary" wire:click="runCommand">Run</button>
                    @endif
                </div>

                <ul class="ledger" style="max-height:46vh;overflow-y:auto">
                    @foreach ($commandSuggestions as $cmd => $desc)
                        <li>
                            <button type="button" class="ledger-row" wire:click="$set('command', '{{ $cmd }}')">
                                <span class="ledger-key">
                                    <span class="readout">{{ $cmd }}</span>
                                    <span class="ledger-sub">{{ $desc }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- The result arrives in marker: the assistant's ink is visibly its own
         until a person accepts it into the document. --}}
    @if ($showResult)
        <div class="ai-result" role="region" aria-label="Assistant result">
            <div class="sheet-head">
                <span class="lamp lamp-marker" aria-hidden="true"></span>
                <h2 class="section-title" style="flex:1 1 auto">In marker — {{ ucfirst($action) }}</h2>
                <button type="button" class="btn btn-primary" wire:click="applyResult">Accept it into the document</button>
                <button type="button" class="btn" wire:click="dismissResult">Drop it</button>
            </div>
            <div class="ai-result-body ink-marker">
                {!! $result !!}
            </div>
        </div>
    @endif
</div>
