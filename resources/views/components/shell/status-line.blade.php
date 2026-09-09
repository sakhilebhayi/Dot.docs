{{--
    The status line: one 36px row of mono readouts across the top of the shell,
    reading like the slug on a press sheet. It is LAST in the DOM inside .shell
    and placed by grid-area, so Tab runs skip link -> rail -> paper -> dock
    before it ever reaches the theme toggle.

    A page may replace the readouts with @section('status'); the default is the
    save lamp, the document's version and word count, and the mode lamp.
--}}
@props(['document' => null, 'theme' => 'dark'])

<header class="status-line" aria-label="Session status">
    <span class="status-brand">Dot.Doc</span>

    @hasSection('status')
        @yield('status')
    @else
        <span class="status-item" id="shell-save" aria-live="polite">
            <span class="lamp lamp-good" aria-hidden="true"></span>
            <span class="readout" data-shell-save-word>Saved</span>
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
