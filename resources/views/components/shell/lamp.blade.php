{{--
    A status lamp is a nib PLUS a word: colour alone never carries state.
    The nib is 8px of tone; the word is set in --text so it always clears the
    4.5:1 gate against both --desk and --desk-raised.

    <x-shell.lamp tone="good" word="Saved" />
--}}
@props([
    'tone' => 'idle',
    'word' => '',
    'title' => null,
])

@php
    $tone = in_array($tone, ['good', 'signal', 'danger', 'marker', 'idle'], true) ? $tone : 'idle';
@endphp

<span {{ $attributes->merge(['class' => 'status-item']) }} @if ($title) title="{{ $title }}" @endif>
    <span class="lamp lamp-{{ $tone }}" aria-hidden="true"></span>
    <span class="readout">{{ $word }}</span>
</span>
