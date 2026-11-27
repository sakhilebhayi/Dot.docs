<div class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Bin</h1>
            <p class="page-lede">Documents deleted in {{ auth()->user()->currentTeam->name ?? 'your personal space' }}. Restoring one brings back every version of it.</p>
        </div>
    </div>

    @if ($restored)
        <p class="note" role="status">
            <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
            {{ $restored }}
            <a href="{{ route('files.index') }}" class="btn btn-quiet btn-sm">Open Files</a>
        </p>
    @endif

    @if ($this->documents->isEmpty())
        <div class="empty">
            <p class="empty-line">Nothing has been deleted here.</p>
        </div>
    @else
        <ul class="list" aria-label="Deleted documents">
            @foreach ($this->documents as $doc)
                <li class="list-row" wire:key="bin-{{ $doc->uuid }}">
                    <span class="list-key">
                        {{ $doc->title ?: 'Untitled' }}
                        <span class="list-sub">
                            Deleted {{ $doc->deleted_at->diffForHumans() }}@if ($doc->owner && $doc->owner_id !== auth()->id()) &middot; {{ $doc->owner->name }}’s @endif
                        </span>
                    </span>
                    <span class="list-val">
                        <x-shell.figure :value="$doc->version" prefix="v" label="Version" />
                    </span>
                    <button type="button" class="btn btn-sm" wire:click="restore('{{ $doc->uuid }}')"
                            wire:loading.attr="disabled" wire:target="restore('{{ $doc->uuid }}')"
                            aria-label="Restore {{ $doc->title ?: 'Untitled' }}">Restore</button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
