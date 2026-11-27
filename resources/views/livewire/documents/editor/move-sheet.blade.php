{{-- Move: the same .sheet pattern as the documents index's rename -
     Escape closes it and returns focus to the control that opened it.
     A folder picker rather than drag-and-drop, so it is keyboard-
     operable from the first commit rather than as a fallback. --}}
@if ($showMoveSheet)
    <div class="scrim" x-data x-trap.noscroll="true" wire:click.self="$set('showMoveSheet', false)" role="dialog" aria-modal="true"
         aria-labelledby="doc-move-title"
         x-on:keydown.escape.window="$wire.set('showMoveSheet', false); $refs.moveTrigger && $refs.moveTrigger.focus()">
        <div class="sheet">
            <div class="sheet-head">
                <h2 class="h-panel" id="doc-move-title">File this document in</h2>
            </div>
            <div class="sheet-body">
                @error('location')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror
                <ul class="list">
                    @foreach ($this->folderChoices as $choice)
                        <li wire:key="move-{{ $choice['uuid'] }}">
                            <button type="button" class="list-row u-row-button"
                                    wire:click="moveTo({{ $choice['id'] }})">
                                <span class="list-key">{{ $choice['label'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn" wire:click="$set('showMoveSheet', false)">Cancel</button>
            </div>
        </div>
    </div>
@endif
