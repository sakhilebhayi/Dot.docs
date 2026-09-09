{{--
    The status line: one 36px row of mono readouts across the top of the shell,
    reading like the slug on a press sheet. It is LAST in the DOM inside .shell
    and placed by grid-area, so Tab runs skip link -> rail -> paper -> dock
    before it ever reaches the theme toggle.

    THE STATE LAMP ONLY EVER REPORTS WHAT THE PAGE TOLD IT.

    It does not follow Livewire's commit cycle: a search box, a filter chip and
    a "mark read" button all commit, and reporting "Saved" for those on a page
    that saves nothing was a lie the writer could not check. A page that owns a
    document registers itself by setting `state-owner` on the item; only then
    does resources/js/shell.js write Saving / Saved / Not saved into it, from
    the `shell:save-state` window event the editor dispatches. Everywhere else
    the word is the page's own, with an idle lamp, and nothing moves it.

    A page may replace the readouts entirely with @section('status').
--}}
@props(['document' => null, 'theme' => 'dark', 'state' => null])

@php
    // Only the editor owns a document, so only there does the lamp become a
    // live save state. Everywhere else the word is stated once and stays put.
    $isEditor = request()->routeIs('documents.edit');
    $stateWord = $state ?? 'Ready';
@endphp

<header class="status-line" aria-label="Session status">
    <span class="status-brand">Dot.Doc</span>

    @hasSection('status')
        @yield('status')
    @else
        <span class="status-item lamp-word"
              id="shell-save"
              aria-live="polite"
              @if ($isEditor) data-shell-save-owner @endif>
            <span class="lamp lamp-idle" aria-hidden="true"></span>
            <span data-shell-save-word>{{ $stateWord }}</span>
        </span>

        @if ($document)
            <span class="status-item">
                <span class="status-item-key">Ver</span>
                <span class="readout">
                    <x-shell.figure :value="$document->version" :width="4" prefix="v" label="Version" />
                </span>
            </span>
            <span class="status-item">
                <span class="status-item-key">Words</span>
                <span class="readout">
                    <x-shell.figure :value="$document->word_count ?? 0" :width="5" label="Words" />
                </span>
            </span>
        @endif
    @endif

    <span class="status-spacer"></span>

    <button type="button"
            class="status-action"
            data-shell-theme-toggle
            aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
        <span class="lamp {{ $theme === 'dark' ? 'lamp-idle' : 'lamp-signal' }}" aria-hidden="true"></span>
        <span data-shell-theme-word>{{ $theme === 'dark' ? 'Night' : 'Day' }}</span>
        <span class="sr-only">Switch to {{ $theme === 'dark' ? 'day' : 'night' }} mode</span>
    </button>
</header>
