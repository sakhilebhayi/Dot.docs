{{--
    A mono figure padded with ghosted leading zeros, the way a counter reads.
    The padding is DECORATION: it is aria-hidden, and the real value rides in
    an .sr-only sibling. That is the one place the contrast floor is relaxed.

    <x-shell.figure :value="7" :width="4" prefix="v" label="Version" />  ->  v0007
--}}
@props([
    'value' => 0,
    'width' => 3,
    'prefix' => '',
    'label' => '',
])

@php
    $digits = (string) max(0, (int) $value);
    $pad = str_repeat('0', max(0, ((int) $width) - strlen($digits)));
@endphp

<span class="figure">
    <span aria-hidden="true">{{ $prefix }}<span class="ghost">{{ $pad }}</span>{{ $digits }}</span>
    <span class="sr-only">{{ trim($label.' '.$digits) }}</span>
</span>
