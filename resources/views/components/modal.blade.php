{{--
    A sheet, not a floating card. Focus is TRAPPED inside it with Alpine's
    Focus plugin (`x-trap.inert.noscroll`), which Livewire 3 bundles: `inert`
    hides the rest of the page from assistive technology, `noscroll` stops the
    desk scrolling behind it, and the trap restores focus to whatever opened
    the sheet when it closes. Without it Tab walked straight out of the dialog
    into the page behind — including "Delete account".
--}}
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
    <div x-show="show"
         x-trap.inert.noscroll="show"
         x-on:click.outside="show = false"
         role="dialog"
         aria-modal="true"
         aria-labelledby="{{ $id }}-title"
         tabindex="-1"
         class="sheet {{ $maxWidth === '4xl' || $maxWidth === '2xl' ? 'sheet-wide' : '' }}">
        {{ $slot }}
    </div>
</div>
