{{-- Pending suggestions: the assistant's ink, still in marker. --}}
@if (count($pendingSuggestions) > 0)
    <section class="panel u-square" aria-labelledby="pending-suggestions">
        <div class="panel-head">
            <h2 class="section-title" id="pending-suggestions">
                Waiting for you — <x-shell.figure :value="count($pendingSuggestions)" label="Suggestions waiting" />
            </h2>
            <span class="micro">Accepted text becomes part of the document</span>
        </div>
        <ul class="list">
            @foreach ($pendingSuggestions as $suggestion)
                <li class="list-row">
                    <x-shell.status-word tone="good" word="In marker" />
                    <span class="list-key">
                        {{ $suggestion['excerpt'] }}
                        <span class="list-sub">{{ $suggestion['user'] }} · {{ $suggestion['created_at'] }}</span>
                    </span>
                    @if ($canEdit)
                        <button type="button" class="btn btn-sm" wire:click="acceptSuggestion({{ $suggestion['id'] }})">Accept</button>
                        <button type="button" class="btn btn-sm" wire:click="rejectSuggestion({{ $suggestion['id'] }})">Drop</button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
