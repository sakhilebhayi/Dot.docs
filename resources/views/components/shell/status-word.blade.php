{{--
    State is a WORD plus a dot, never colour alone, and never a bordered
    compartment: the dot is 6px of tone, the word is set in --ink and so clears
    4.5:1 against every ground in the shell by construction.

    Three tones and no more. `good` is --accent (done, on, published), `danger`
    is --danger (it failed, it expired), `idle` is --ink-soft (nothing to
    report, or something still in flight — the word carries that, not a colour).
    Anything else falls back to idle rather than emitting a class that no rule
    paints.

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
    <span>{{ $word }}</span>
</span>
