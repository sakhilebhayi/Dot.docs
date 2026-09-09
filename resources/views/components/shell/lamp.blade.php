{{--
    A status lamp is a nib PLUS a word: colour alone never carries state.
    The nib is 8px of tone; the word is set in --text so it always clears the
    4.5:1 gate against both --desk and --desk-raised. Inside an inverted
    surface (a primary button, a pressed tag, an active tool) both inherit —
    see the "inverted surfaces" block in resources/css/shell.css.

    It renders BARE: no padding, no hairline. Drop it in a ledger row, a
    toolbar or a button and it sits where it lands. The status line is the one
    place the compartment padding belongs, and it asks for it:

    <x-shell.lamp tone="good" word="Saved" />
    <x-shell.lamp tone="good" word="Saved" variant="status" />
--}}
@props([
    'tone' => 'idle',
    'word' => '',
    'variant' => 'inline',
    'title' => null,
])

@php
    $tone = in_array($tone, ['good', 'signal', 'danger', 'marker', 'idle'], true) ? $tone : 'idle';
    $class = $variant === 'status' ? 'status-item lamp-word' : 'lamp-word';
@endphp

<span {{ $attributes->merge(['class' => $class]) }} @if ($title) title="{{ $title }}" @endif>
    <span class="lamp lamp-{{ $tone }}" aria-hidden="true"></span>
    <span>{{ $word }}</span>
</span>
