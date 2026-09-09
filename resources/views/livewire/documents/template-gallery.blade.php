<div>
    @if ($show)
        <div class="scrim" role="dialog" aria-modal="true" aria-labelledby="gallery-title" wire:click.self="close">
            <div class="sheet sheet-wide">
                <div class="sheet-head">
                    <h2 class="h-panel" id="gallery-title">Start from a template</h2>
                    <button type="button" class="btn btn-quiet btn-sm" wire:click="close">Close</button>
                </div>

                <div class="panel-head" role="group" aria-label="Template categories">
                    <div class="toolbar">
                        @foreach ($this->categories as $cat)
                            <button type="button" class="tag" wire:click="$set('activeCategory', '{{ $cat }}')"
                                    aria-pressed="{{ $activeCategory === $cat ? 'true' : 'false' }}">{{ ucfirst($cat) }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="sheet-body" style="padding:0">
                    @if ($this->templates->isEmpty())
                        <div class="empty">
                            <p class="empty-line">Nothing filed under {{ $activeCategory }} yet.</p>
                            <button type="button" class="btn" wire:click="$set('activeCategory', 'all')">Show every template</button>
                        </div>
                    @else
                        <ul class="ledger">
                            @foreach ($this->templates as $template)
                                <li class="ledger-row">
                                    <span class="ledger-key">
                                        {{ $template->name }}
                                        <span class="ledger-sub">
                                            {{ $template->description ?: Str::limit(strip_tags($template->content), 120) }}
                                        </span>
                                    </span>
                                    <span class="ledger-val">{{ ucfirst($template->category) }}</span>
                                    @if ($template->is_global)
                                        <x-shell.lamp tone="good" word="Built in" />
                                    @endif
                                    <button type="button" class="btn btn-sm btn-primary"
                                            wire:click="useTemplate({{ $template->id }})" wire:loading.attr="disabled">
                                        Use it
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
