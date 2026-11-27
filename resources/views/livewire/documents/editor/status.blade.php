{{-- State is a WORD and a dot, never colour alone. A rejected save
     has to be visible: the writer keeps typing over content the
     server never accepted, and the offline draft is deliberately
     kept as the only remaining copy. The same words go to the
     top bar through the `shell:save-state` event, which is also
     where a screen reader hears them: this strip is visual only,
     so an autosave is not announced twice. Its alerts (a failed
     save, an assistant error) speak for themselves. --}}
<span class="doc-status">
    <x-shell.status-word tone="idle" word="Offline" x-show="isOffline"
                         title="Edits are saved in this browser and sync when you are back online." />

    <x-shell.status-word tone="idle" word="Editing" x-show="isTyping && !isOffline" />

    <x-shell.status-word tone="idle" word="Saving"
                         wire:loading wire:target="saveContent,saveTitle" />

    {{-- wire:ignore: the sentence is written by Alpine (x-text),
         and a Livewire morph evaluates x-text on the incoming
         copy of this element against the newest data object,
         where aiError is empty, then removes the live text: the
         word stayed up with nothing in it. Nothing here is
         rendered by the server, so the morph can skip it
         (.ai/rules/livewire.md). --}}
    {{-- EA-8 (A-05): a real button, so the keyboard can dismiss it too. --}}
    {{-- The alert is its own element, always in the page (wire:ignore, so the morph leaves its x-text alone), and
         only its text changes: the button around the visible sentence is shown and hidden, and an alert inside it
         arrived together with its words, which screen readers do not reliably announce (R2-12). --}}
    <button type="button" class="status-word status-word-danger u-bare-button" wire:ignore x-show="aiError" x-cloak
            @click="aiError = ''" title="Dismiss"
           >
        <span class="status-word-dot" aria-hidden="true"></span>
        <span x-text="aiError"></span>
        <span class="sr-only">(dismiss)</span>
    </button>
    <span class="sr-only" role="alert" wire:ignore x-text="aiError" data-ai-error-said></span>

    {{-- A newer version was saved elsewhere while this tab held
         unsaved typing, and saving is suspended until the writer
         chooses. The strip says so in two words. The sentence and
         the two buttons are in the notice bar under this row: the
         strip is one line that does not wrap, and a notice with
         buttons in it ended up outside the window or under the
         dock. Shown with x-show: x-show is the one Alpine binding
         a Livewire morph leaves as it is on the live element
         (.ai/rules/livewire.md). Like the Saved / Ready word
         below, the x-show sits on a wrapper that is rendered the
         same on EVERY render, and the word inside it is rendered
         only while no content error says Not saved already: with
         a conflict standing AND a rejected save, the strip said
         Not saved twice, side by side. --}}
    <span x-show="conflict" x-cloak>
        @unless ($errors->has('content'))
            <x-shell.status-word tone="danger" word="Not saved" />
        @endunless
    </span>

    {{-- Said when it appears (R2-6 / A-03): a save that failed must not be silent to a screen reader. The region is
         rendered on every render and only its words come and go (R2-12), so the change is announced, and a refusal
         that repeats on the next autosave with the same words is not said again. Polite, like the top bar's save
         word: it is an autosave outcome, not an interruption. sr-only (out of the strip's flex row), and the word
         the eye reads beside it is hidden from screen readers, so the reason is read once. The top bar says Not saved;
         this says why. --}}
    <span class="sr-only" role="status" data-content-error>@error('content')The last save was refused: {{ $message }}@enderror</span>
    @error('content')
        <x-shell.status-word tone="danger" :title="$message" aria-hidden="true"
                             :word="'Not saved — '.\Illuminate\Support\Str::limit($message, 60)" />
    @enderror

    {{-- The x-show sits on a wrapper that is rendered the same on
         EVERY render, and the Saved / Ready word inside it comes and
         goes with the error above. With the x-show on the word
         itself, a word that came back after a rejected save was a
         new element, bound to the newest Alpine data object, whose
         conflict, syncNotice and isTyping never change
         (.ai/rules/livewire.md): Saved then showed beside the
         conflict notice and while typing.

         `!unsaved`: the word inside is whatever the server last
         rendered, so it would say Saved whenever the writer
         paused. `unsaved` is the page's own knowledge that the
         editor holds text no accepted save has stored (sync/host.js),
         and Saved is not said over such text: not after a save
         that failed or never answered, and not in the moment
         between the end of typing and the autosave. The strip
         then shows no word at all until the save is sent
         (Saving), stored (Saved) or refused (Not saved); the top
         bar keeps its own word throughout. An edit undone again
         clears the flag on the next poll that completes
         (settleIfBackAtConfirmed() in sync/host.js). --}}
    <span x-show="!isTyping && !isOffline && !conflict && !syncNotice && !unsaved">
        @if (! $canEdit)
            {{-- Somebody who may read and not change the document
                 has nothing to save: the word says what they have
                 instead of Saved. --}}
            <x-shell.status-word tone="idle" word="Read only" data-read-only-word />
        @else
            @unless ($errors->has('content'))
                <x-shell.status-word tone="good" :word="$saved ? 'Saved' : 'Ready'" data-saved-word
                                     wire:loading.remove wire:target="saveContent,saveTitle" />
            @endunless
        @endif
    </span>

    {{-- The version a steady save answers with: a successful save no longer
         re-renders this page (EA-5 / P-03), so the number follows the
         page's own baseVersion, which every save and every followed
         change moves up. The server's figure is the first paint. --}}
    <span class="micro">
        <span class="numeral"><span data-doc-version wire:ignore>v{{ number_format($document->version) }}</span><span class="sr-only"> Version</span></span>
    </span>
</span>
