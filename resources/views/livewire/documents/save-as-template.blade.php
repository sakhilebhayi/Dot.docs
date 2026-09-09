<div>
    @if ($show)
        <div class="scrim" role="dialog" aria-modal="true" aria-labelledby="tpl-title"
             wire:click.self="$set('show', false)">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="tpl-title">Save this as a template</h2>
                    <button type="button" class="btn btn-quiet btn-sm" wire:click="$set('show', false)">Close</button>
                </div>

                <div class="sheet-body">
                    <div class="field-row" style="margin-top:0">
                        <label class="field-label" for="tpl-name">Template name</label>
                        <input id="tpl-name" wire:model="name" type="text" class="field" />
                        @error('name')
                            <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field-row">
                        <label class="field-label" for="tpl-category">Category</label>
                        <select id="tpl-category" wire:model="category" class="field">
                            @foreach ($categories as $cat)
                                <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field-row">
                        <label class="field-label" for="tpl-description">Description</label>
                        <textarea id="tpl-description" wire:model="description" rows="2" class="field"
                                  placeholder="When would somebody reach for this one?"></textarea>
                        <p class="field-hint">Optional, but it is what people read in the gallery.</p>
                    </div>

                    @if (auth()->user()->currentTeam)
                        <label class="field-check" for="tpl-share" style="margin-top:var(--s4)">
                            <input id="tpl-share" wire:model="shareWithTeam" type="checkbox" class="field-box" />
                            <span>Share it with {{ auth()->user()->currentTeam->name }}</span>
                        </label>
                    @endif
                </div>

                <div class="sheet-foot">
                    <button type="button" class="btn" wire:click="$set('show', false)">Cancel</button>
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save the template</button>
                </div>
            </div>
        </div>
    @endif
</div>
