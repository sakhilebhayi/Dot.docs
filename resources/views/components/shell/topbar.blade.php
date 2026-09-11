{{--
    The top bar. One 52px row across the shell: what this page is, what state it
    is in, and the controls that act on the whole document. It replaces the
    status line's row of mono figures — no platform name, no version, no word
    count. Those facts live in the (collapsed-by-default) left panel now.

    It is LAST in the DOM inside .shell and placed by grid-area, so Tab still
    runs skip link -> rail -> canvas -> dock before it reaches the theme toggle.

    THE STATUS WORD ONLY EVER REPORTS WHAT THE PAGE TOLD IT.

    It does not follow Livewire's commit cycle: a search box, a filter chip and
    a "mark read" button all commit, and reporting "Saved" for those on a page
    that saves nothing was a lie the writer could not check. A page that owns a
    document registers itself with `data-shell-save-owner`; only then does
    resources/js/shell.js write Saving / Saved / Not saved into it from the
    `shell:save-state` window event the editor dispatches.

    The rail's single toggle lives here, beside the title it reveals, because
    the panels default to collapsed on the editor (spec §3) and a panel with no
    visible control is a panel nobody finds. The dock's toggle is the last item
    in `actions`, at the edge it opens from.
--}}
@props([
    'title' => null,
    'saveState' => ['word' => 'Ready', 'tone' => 'idle'],
    'railExpanded' => true,
    'saveOwner' => false,
])

<header {{ $attributes->merge(['class' => 'topbar']) }}>
    <button type="button"
            class="topbar-toggle"
            data-shell-panel-toggle="rail"
            aria-controls="shell-rail"
            aria-expanded="{{ $railExpanded ? 'true' : 'false' }}">
        <span data-shell-rail-word>{{ $railExpanded ? 'Hide the panel' : 'Show the panel' }}</span>
    </button>

    <div class="topbar-title">{{ $title }}</div>

    {{-- `:data-shell-save-owner="... ?: null"` and NOT `@if ($saveOwner) ...
         @endif` inside the tag: Blade's component compiler does not parse
         directives in an attribute list, so the @if form leaves the whole tag
         in the output as literal text. A null attribute value is dropped by
         ComponentAttributeBag, a true one renders the flag. --}}
    <x-shell.status-word :tone="$saveState['tone']"
                         :word="$saveState['word']"
                         id="shell-save"
                         class="topbar-status"
                         aria-live="polite"
                         :data-shell-save-owner="$saveOwner ? true : null" />

    <div class="topbar-actions">{{ $actions ?? '' }}</div>
</header>
