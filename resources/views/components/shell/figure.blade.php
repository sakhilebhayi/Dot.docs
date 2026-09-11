{{--
    A figure is a PLAIN NUMERAL in the UI font — no zero padding, no ghosted
    digits, no aria-hidden decoration with the real value hidden in a sibling.
    That device belonged to the control-room language Fair Copy retires
    (spec §2.2); it also bought the contrast gate its one exemption, which is
    now gone too.

    The label rides along in an .sr-only span so "v7" is read as "v7 Version"
    rather than as a bare number.

    <x-shell.figure :value="7" prefix="v" label="Version" />  ->  v7
--}}
@props([
    'value' => 0,
    'prefix' => '',
    'label' => '',
])

@php
    $digits = number_format(max(0, (int) $value));
@endphp

<span {{ $attributes->merge(['class' => 'numeral']) }}>{{ $prefix }}{{ $digits }}@if ($label)<span class="sr-only"> {{ $label }}</span>@endif</span>
