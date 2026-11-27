{{-- ── The persistent bar ───────────────────────────────────────────
     ONE slim row under the top bar, and nothing on it inserts a block.
     Spec §4 retired the bench: the twelve formatting buttons that used to
     wrap into three ragged rows are now either on the floating toolbar
     that follows the selection (marks, heading level, table and image
     tools — resources/js/editor/ui/bubble.js) or in the two menus that
     already listed them, `/` and ⌘K.

     What is left is what has to be true all the time: what the document is
     called, which style it is set in, where it is filed, who else is here,
     whether it is saved, and which version that is. The ⌘K button is the
     door to everything else, and "More" holds the actions that act on the
     whole document rather than on the text under the cursor.

     The document's title is here, and ONLY here: the top bar deliberately
     does not repeat it on this route (layouts/app.blade.php), because a
     title you can read in two places but edit in one is a title people
     edit in the wrong one. --}}
<div class="doc-bar">
    {{-- The page's one <h1>. The title is edited through the input
         beside it, so the heading is for the document outline and for
         assistive technology; Livewire re-renders both together. --}}
    <h1 class="sr-only">{{ $title ?: 'Untitled' }}</h1>

    <label class="sr-only" for="doc-title">Document title</label>
    <input id="doc-title"
           wire:model.blur="title"
           wire:change="saveTitle"
           type="text"
           class="doc-title-field"
           placeholder="Untitled"
           @readonly(! $canEdit) />

    @include('livewire.documents.editor.tools')

    @if (count($activeUsers) > 0)
        <div class="presence" role="group" aria-label="People here now">
            @foreach (array_slice($activeUsers, 0, 4) as $member)
                <span class="presence-face" title="{{ $member['name'] }}">
                    @if (! empty($member['avatar']))
                        <img src="{{ $member['avatar'] }}" alt="{{ $member['name'] }}" />
                    @else
                        {{-- The initial is the picture; the person's name is what is read (R2-9). --}}
                        <span aria-hidden="true">{{ strtoupper(substr($member['name'], 0, 1)) }}</span><span class="sr-only">{{ $member['name'] }}</span>
                    @endif
                </span>
            @endforeach
            @if (count($activeUsers) > 4)
                <span class="presence-face"><span aria-hidden="true">+{{ count($activeUsers) - 4 }}</span><span class="sr-only">and {{ count($activeUsers) - 4 }} more</span></span>
            @endif
        </div>
    @endif

    @include('livewire.documents.editor.status')

    @include('livewire.documents.editor.more-menu')
</div>
