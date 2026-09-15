{{--
    State is a WORD plus a dot, never colour alone, and never a bordered
    compartment: the dot is 6px of tone, the word is set in --ink and so clears
    4.5:1 against every ground in the shell by construction.

    THREE TONES, and they are about ATTENTION, not about sentiment:

    - `good`   — --accent. Something is ON, PRESENT or SETTLED and the eye may
                 rest: Saved, Public, Resolved, Current, Built in, This device,
                 Document, File, and the unread dot on the notification bell.
                 It is the shell's one positive mark and it is deliberately
                 broad; "done/published" alone would need a second, near
                 identical green for "on", and two greens a reader cannot tell
                 apart are worse than one they can.
    - `danger` — --danger. It failed, it expired, it was rejected.
    - `idle`   — --ink-soft. Nothing to report, or something still in flight
                 (Pending, Thinking, Invited). The WORD carries that, not a
                 colour.

    Anything else falls back to idle rather than emitting a class no rule
    paints. A fourth tone would be a fourth thing to tell apart at 6px, which is
    the size at which distinctions stop being legible.

    It renders bare: no padding, no hairline, no border-right override needed at
    any call site. Drop it in a list row, a toolbar or a button.

    <x-shell.status-word tone="good" word="Saved" />
--}}
@props(['tone' => 'idle', 'word' => ''])

@php
    $tone = in_array($tone, ['good', 'danger', 'idle'], true) ? $tone : 'idle';
@endphp

<span {{ $attributes->merge(['class' => 'status-word status-word-'.$tone]) }}>
    <span class="status-word-dot" aria-hidden="true"></span>
    {{-- `data-shell-save-word` is how resources/js/shell.js finds the word to
         rewrite in the top bar's #shell-save. It is on the span rather than
         left to lastElementChild so adding anything else inside a status word
         later cannot silently break the save state. --}}
    <span data-shell-save-word>{{ $word }}</span>
</span>
