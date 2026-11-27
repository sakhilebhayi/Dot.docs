{{-- ── The notice bar ───────────────────────────────────────────────
     What the writer has to read in full, and what they have to
     answer, is said here and not in the one-line status strip above:
     this bar is as wide as the editor column, its text wraps, and
     its buttons drop onto a line of their own when there is no room
     beside the text. It takes no space while nothing is showing.

     It is a live region, so a notice is announced when it appears.

     The whole bar is wire:ignore, and its rows are switched with the
     `hidden` attribute (x-bind:hidden), not with x-show. Both for a
     reason (.ai/rules/livewire.md):

     - Every Livewire morph initialises the INCOMING copy of each
       element it patches against the root's newest Alpine data
       object, whose conflict, setAside and syncNotice never change,
       and copies the outcome onto the live element. Only x-show is
       guarded against that. A render while the conflict notice
       showed disabled Load theirs, and one while a sync notice
       showed took its sentence away for good. Livewire skips a
       wire:ignore element before it clones anything, so nothing in
       here is evaluated or patched by a morph. The price: nothing
       in here may be rendered by the server. No Blade condition, no
       echoed value. It is static markup that Alpine shows, hides
       and fills.
     - After its first evaluation x-show applies a change (a hide or
       a show) only inside requestAnimationFrame while the page
       reports itself visible. In a tab that is not being painted
       the frame never comes: a notice stayed up although its state
       was gone, and one that should have appeared would not have.
       x-bind:hidden writes the attribute in Alpine's own flush
       after the change and waits for no frame. The rows carry
       `hidden` in the markup, so nothing shows before Alpine runs
       (`.doc-notice[hidden]` in shell.css: the row is display:flex,
       which beats the browser's own [hidden] rule).
       Do NOT use x-bind:hidden on anything outside a wire:ignore
       element: without the x-show guard a morph writes the newest
       object's answer straight onto the live element. --}}
<div class="doc-notices" role="status" aria-live="polite" aria-atomic="false" wire:ignore
     @click="noticePressed($event)">
    {{-- A newer version was saved elsewhere while this tab held
         unsaved typing. Saving is suspended until the writer picks
         one: nothing is overwritten and nothing is thrown away
         without being asked.

         The sentence used to end in Do not reload. A reload instead
         of a choice opens the newer version, and this tab's text is
         then in the offline draft, which the page finds older than
         the document, sets aside and offers back in the next row
         (as a draft, so Put it back asks first) - unless a second
         tab of the same browser is open on the document: the draft
         is keyed per document, not per tab, and that tab clears it
         when it follows the save. A browser that cannot keep a
         draft loses the text to a reload too.

         What each button does is in its title and, for a keyboard
         or a screen reader, in the element it is described by. --}}
    <div class="doc-notice" hidden x-bind:hidden="!conflict">
        <p class="doc-notice-text">
            <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span>
            <span>Not saved — this document was changed elsewhere while you were typing, and the two could not be combined. Choose one.</span>
        </p>
        <span class="doc-notice-actions">
            <button type="button" class="tool tool-mono" @click="keepMine()"
                    aria-describedby="doc-notice-keep-mine-does"
                    title="Save your version over the newer one. The other version is kept in the history.">Keep mine</button>
            <button type="button" class="tool tool-mono" @click="loadTheirs()"
                    :disabled="!conflict || !conflict.ready"
                    aria-describedby="doc-notice-load-theirs-does"
                    title="Show the newer version. You can put your text back afterwards.">Load theirs</button>
            <span id="doc-notice-keep-mine-does" hidden>Save your version over the newer one. The other version is kept in the history.</span>
            <span id="doc-notice-load-theirs-does" hidden>Show the newer version. You can put your text back afterwards.</span>
        </span>
    </div>

    {{-- ── The lost row (Phase 2) ────────────────────────────────
         What a merge could not keep: a paragraph both people changed
         at the same moment (theirs stays on the page, this is yours),
         or one somebody removed while you were typing in it. Each
         item is offered back on its own: Put it back puts it below
         the version that is there now, or where it was before it was
         removed, as an ordinary edit the autosave sends. Items are
         appended and never withdrawn; a copy of the whole list is
         parked in this browser for 7 days WHERE IT KEEPS DRAFTS
         (sync/host.js, offerLost(): a write that fails is not
         reported, so the header does not promise the copy; the
         Dismiss all question and title say it, and ask again when
         the copy could not be written).

         Five STATIC item slots switched with hidden, not x-for: the
         wiring test pins every element, and no Alpine pattern new to
         this bar is introduced (the Phase 1 bar faults were only
         visible in a browser). LOST_ROWS in sync/host.js is the same
         five; a sixth item and beyond are counted in the last line and
         come back with Put them all back. Nothing here is rendered by
         the server: Livewire ignores the whole bar. --}}
    <div class="doc-notice" hidden x-bind:hidden="!lost.length">
        <p class="doc-notice-text">
            <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
            <span>Not everything you typed could be kept. Each item below stays here until you put it back or dismiss it.</span>
        </p>
        <ul class="doc-notice-list">
            <li class="doc-notice-item" hidden x-bind:hidden="!lost[0]">
                <span class="sr-only" id="doc-notice-lost-0-count" x-text="lost.length > 1 ? 'Item 1 of ' + lost.length + '. ' : ''"></span>
                <span class="doc-notice-sentence" id="doc-notice-lost-0-sentence" x-text="lost[0] ? lost[0].sentence : ''"></span>
                <q class="doc-notice-excerpt" id="doc-notice-lost-0-excerpt" x-text="lost[0] ? lost[0].excerpt : ''"></q>
                <button type="button" class="tool tool-mono" @click="putBackLost(0, $event.detail === 0)"
                        aria-describedby="doc-notice-lost-0-count doc-notice-lost-0-sentence doc-notice-lost-0-excerpt doc-notice-put-back-lost-does"
                        title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
            </li>
            <li class="doc-notice-item" hidden x-bind:hidden="!lost[1]">
                <span class="sr-only" id="doc-notice-lost-1-count" x-text="lost.length > 1 ? 'Item 2 of ' + lost.length + '. ' : ''"></span>
                <span class="doc-notice-sentence" id="doc-notice-lost-1-sentence" x-text="lost[1] ? lost[1].sentence : ''"></span>
                <q class="doc-notice-excerpt" id="doc-notice-lost-1-excerpt" x-text="lost[1] ? lost[1].excerpt : ''"></q>
                <button type="button" class="tool tool-mono" @click="putBackLost(1, $event.detail === 0)"
                        aria-describedby="doc-notice-lost-1-count doc-notice-lost-1-sentence doc-notice-lost-1-excerpt doc-notice-put-back-lost-does"
                        title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
            </li>
            <li class="doc-notice-item" hidden x-bind:hidden="!lost[2]">
                <span class="sr-only" id="doc-notice-lost-2-count" x-text="lost.length > 1 ? 'Item 3 of ' + lost.length + '. ' : ''"></span>
                <span class="doc-notice-sentence" id="doc-notice-lost-2-sentence" x-text="lost[2] ? lost[2].sentence : ''"></span>
                <q class="doc-notice-excerpt" id="doc-notice-lost-2-excerpt" x-text="lost[2] ? lost[2].excerpt : ''"></q>
                <button type="button" class="tool tool-mono" @click="putBackLost(2, $event.detail === 0)"
                        aria-describedby="doc-notice-lost-2-count doc-notice-lost-2-sentence doc-notice-lost-2-excerpt doc-notice-put-back-lost-does"
                        title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
            </li>
            <li class="doc-notice-item" hidden x-bind:hidden="!lost[3]">
                <span class="sr-only" id="doc-notice-lost-3-count" x-text="lost.length > 1 ? 'Item 4 of ' + lost.length + '. ' : ''"></span>
                <span class="doc-notice-sentence" id="doc-notice-lost-3-sentence" x-text="lost[3] ? lost[3].sentence : ''"></span>
                <q class="doc-notice-excerpt" id="doc-notice-lost-3-excerpt" x-text="lost[3] ? lost[3].excerpt : ''"></q>
                <button type="button" class="tool tool-mono" @click="putBackLost(3, $event.detail === 0)"
                        aria-describedby="doc-notice-lost-3-count doc-notice-lost-3-sentence doc-notice-lost-3-excerpt doc-notice-put-back-lost-does"
                        title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
            </li>
            <li class="doc-notice-item" hidden x-bind:hidden="!lost[4]">
                <span class="sr-only" id="doc-notice-lost-4-count" x-text="lost.length > 1 ? 'Item 5 of ' + lost.length + '. ' : ''"></span>
                <span class="doc-notice-sentence" id="doc-notice-lost-4-sentence" x-text="lost[4] ? lost[4].sentence : ''"></span>
                <q class="doc-notice-excerpt" id="doc-notice-lost-4-excerpt" x-text="lost[4] ? lost[4].excerpt : ''"></q>
                <button type="button" class="tool tool-mono" @click="putBackLost(4, $event.detail === 0)"
                        aria-describedby="doc-notice-lost-4-count doc-notice-lost-4-sentence doc-notice-lost-4-excerpt doc-notice-put-back-lost-does"
                        title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
            </li>
            <li class="doc-notice-item" hidden x-bind:hidden="lost.length <= 5"><span>And <span x-text="lost.length - 5"></span> more. Put them all back restores every item, these included.</span></li>
        </ul>
        <span class="doc-notice-actions">
            <button type="button" class="tool tool-mono" @click="putBackAllLost($event.detail === 0)"
                    aria-describedby="doc-notice-put-back-all-lost-does"
                    title="Put every item back on the page, oldest first: each below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put them all back</button>
            <button type="button" class="tool tool-mono" @click="dismissLost()"
                    aria-describedby="doc-notice-dismiss-lost-does"
                    title="Remove these items from this page. The text is in no saved version; it is kept only in this browser, for 7 days, where this browser keeps drafts. If this browser cannot keep it, you are asked again before it is dropped.">Dismiss all</button>
            <span id="doc-notice-put-back-lost-does" hidden>Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.</span>
            <span id="doc-notice-put-back-all-lost-does" hidden>Put every item back on the page, oldest first: each below the version that is there now, or where it was before it was removed. Nothing else on the page changes.</span>
            <span id="doc-notice-dismiss-lost-does" hidden>Remove these items from this page. The text is in no saved version; it is kept only in this browser, for 7 days, where this browser keeps drafts. If this browser cannot keep it, you are asked again before it is dropped.</span>
        </span>
    </div>

    {{-- Text the page holds for the writer, and where it came from
         (setAsideFrom, set by sync/host.js):

         - conflict: the writer chose Load theirs a moment ago, and
           this is what the tab held. The page took it away, so it
           says so, and says what Put it back does.
         - anything else (draft): a draft from an earlier visit that
           the document has since moved past. The page took nothing
           away, and it cannot tell text that was never stored from
           text the unload beacon stored and others then built on,
           so it does not say Your text was set aside: it says what
           it found.

         Put it back replaces the whole page, and through the save
         that follows the stored document; the server keeps the
         version it replaces. putBack() asks first (a browser
         confirm) unless the text came from Load theirs, the
         document has not moved since and nothing on the page is
         unsaved. Discard only removes the offer: nothing is sent
         and the page is not touched. The copy parked under
         stale-<uuid> when the text was set aside stays for its 7
         days in a browser that keeps drafts, but nothing in the
         page offers it again. That is why Discard asks first when
         the text came from Load theirs (discardSetAside() in
         sync/host.js): that text is the writer's own unsaved
         typing, in no saved version. A draft from an earlier visit
         is withdrawn without a question. --}}
    <div class="doc-notice" hidden x-bind:hidden="!setAside">
        <p class="doc-notice-text">
            <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
            <span hidden x-bind:hidden="setAsideFrom !== 'conflict'">Your text was set aside. Put it back replaces what is on the page now; the version it replaces is kept in the history.</span>
            <span hidden x-bind:hidden="setAsideFrom === 'conflict'">A draft from your last visit here differs from the document as it is now. It may already be part of it.</span>
        </p>
        <span class="doc-notice-actions">
            <button type="button" class="tool tool-mono" @click="putBack()"
                    aria-describedby="doc-notice-put-back-does"
                    title="Replace what is on the page now with this text. The version it replaces is kept in the history.">Put it back</button>
            <button type="button" class="tool tool-mono" @click="discardSetAside()"
                    aria-describedby="doc-notice-discard-does"
                    title="Remove this notice and leave the page as it is. Text set aside by Load theirs is in no saved version, so you are asked first; it is removed from this page and kept only in this browser, for 7 days, where this browser keeps drafts.">Discard</button>
            <span id="doc-notice-put-back-does" hidden>Replace what is on the page now with this text. The version it replaces is kept in the history.</span>
            <span id="doc-notice-discard-does" hidden>Remove this notice and leave the page as it is. Text set aside by Load theirs is in no saved version, so you are asked first; it is removed from this page and kept only in this browser, for 7 days, where this browser keeps drafts.</span>
        </span>
    </div>

    {{-- Somebody who may read this document and not change it. Said
         once, in plain words, for as long as the page is open: the
         editor is not editable and nothing is ever saved from here,
         and what other people write still arrives. Bound to
         `editable` (the server-drawn literal at the top of the
         x-data object; the host sets it false when a save is refused),
         and hidden while a stop reason is up, which says more. The
         second sentence shows only after a refusal that left words
         unsent, or text that the lost row or the set-aside row was
         offering (those rows are taken away then, and the words stay
         parked in this browser). Static markup, as everything
         in this bar is. --}}
    <div class="doc-notice" hidden x-bind:hidden="editable || !!syncNotice">
        <p class="doc-notice-text">
            <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
            <span>You can read this document but not change it.</span>
            <span hidden x-bind:hidden="!unsentKept">Your unsent words are not saved. A copy is kept in this browser for 7 days.</span>
        </p>
    </div>

    {{-- The page can no longer stay in step: signed out, access
         removed, the document gone, or a version this editor cannot
         open. Also said here: a newer version or the writer's own
         text that could not be put into the editor, and the lost
         row's three notices (a block that could not be put back, a
         Dismiss all that could not keep its browser copy or found
         the list changed). Those three are never written over one of
         the stop reasons and go when the list changes or empties
         (sync/host.js, setLostNotice() and clearLostNotices()). --}}
    <div class="doc-notice" hidden x-bind:hidden="!syncNotice">
        <p class="doc-notice-text">
            <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span>
            <span x-text="syncNotice"></span>
        </p>
    </div>
</div>
