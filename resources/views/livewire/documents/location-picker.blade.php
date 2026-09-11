{{--
    The location field of the smart-save sheet.

    Whitespace does the dividing, as everywhere else in Fair Copy: the crumb
    trail, the sentence that names the destination and the folder list are
    separated by space, and the only rule on screen is the one the list rows
    already carry.

    Opening a folder IS choosing it — there is no second "select" control per
    row to get out of step with where you are standing. The crumbs walk back
    up, and the sentence under them always names what resolve() will return.
--}}
<div class="field-row">
    <span class="field-label" id="location-picker-label">Location</span>

    <nav class="toolbar" aria-label="Folder path" style="margin-bottom:var(--s2)">
        @foreach ($trail as $crumb)
            @if (! $loop->first)
                <span class="micro" aria-hidden="true">/</span>
            @endif
            <button type="button" class="btn btn-quiet btn-sm"
                    wire:click="selectFolder({{ $crumb->id }})"
                    @if ($loop->last) aria-current="true" @endif>
                {{ $crumb->parent_id === null ? 'All documents' : $crumb->name() }}
            </button>
        @endforeach
    </nav>

    @if ($current === null)
        <p class="empty-line">There is no workspace to file this in yet.</p>
    @else
        <p class="micro">
            Filing it in {{ $current->parent_id === null ? 'All documents' : $current->name() }}.
        </p>

        @if ($folders->isEmpty())
            <p class="micro">No folders inside this one — it will be filed here.</p>
        @else
            <ul class="list" aria-labelledby="location-picker-label">
                @foreach ($folders as $folder)
                    <li>
                        <button type="button" class="list-row" wire:click="selectFolder({{ $folder->id }})">
                            <span class="list-key">
                                {{ $folder->name() }}
                                <span class="list-sub">Open it and file the document here</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
