@props(['id' => null, 'maxWidth' => null])

@php
    $id = $id ?? md5($attributes->wire('model'));
@endphp

<div
    x-data="{ show: @entangle($attributes->wire('model')).live }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    id="{{ $id }}"
    class="scrim"
    style="display: none;"
>
    <div x-show="show" x-on:click.outside="show = false" class="sheet {{ $maxWidth === '4xl' || $maxWidth === '2xl' ? 'sheet-wide' : '' }}">
        {{ $slot }}
    </div>
</div>
