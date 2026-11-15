{{--
  <x-dot.nav-menu>: one dropdown group of the primary nav (a secondary-items menu). Print it inside the header's nav list, or let
  <x-dot.header :nav="[...]"> print it for every entry that has `items`. Disclosure pattern (button + panel, aria-expanded), not a
  role=menu: the items are links. js/dot-nav.js opens it (click, Enter, Space, ArrowDown), walks it with the arrow keys, closes it on
  Escape, outside click and Tab-out, and closes every other open menu when it opens. It never opens on hover.

  Props
    label    the button text (required)
    items    list of ['label' => 'Rates', 'href' => '/rates', 'current' => false, 'badge' => null, 'attrs' => ['wire:navigate' => true],
             'heading' => null]  (an entry with only 'heading' prints a small group heading; with only 'rule' => true a divider)
    id       the panel id (default: derived from the label, the same on every render)
    align    'start' (default) or 'end': which edge the panel hangs from (js still flips it when it would leave the screen)
  Slot
    default  replaces the item list (your own <a class="dh-menu__item"> markup)
  It prints an <li>: place it in a <ul class="dh-nav__list">. The group counts as current when any item is.
--}}
@props([
    'label',
    'items' => [],
    'id' => null,
    'align' => 'start',
])
@php
    $panelId = $id ?: 'dh-menu-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $label)), '-');
    $hasCurrent = collect((array) $items)->contains(fn ($i) => ! empty($i['current']));
@endphp
<li {{ $attributes->class(['dh-nav__item']) }} data-dh-menu data-dh-label="{{ $label }}" @if($hasCurrent) data-has-current @endif>
    <button type="button" class="dh-nav__link" data-dh-menu-trigger aria-expanded="false" aria-controls="{{ $panelId }}">{{ $label }}<span class="dh-chev" aria-hidden="true"></span></button>
    <div id="{{ $panelId }}" class="dh-menu" @if($align === 'end') data-align="end" @endif data-dh-menu-panel hidden>
        @if (isset($slot) && ! $slot->isEmpty())
            {{ $slot }}
        @else
            @foreach ((array) $items as $item)
                @if (! empty($item['rule']))
                    <div class="dh-menu__rule" role="separator"></div>
                @elseif (isset($item['heading']) && ! isset($item['href']))
                    <p class="dh-menu__head">{{ $item['heading'] }}</p>
                @else
                    <a class="dh-menu__item" href="{{ $item['href'] ?? '#' }}" @if(! empty($item['current'])) aria-current="page" @endif {{ new \Illuminate\View\ComponentAttributeBag($item['attrs'] ?? []) }}><span class="dh-menu__label">{{ $item['label'] ?? '' }}</span>@if(isset($item['badge']) && $item['badge'] !== '' && $item['badge'] !== null)<span class="dh-count">{{ $item['badge'] }}</span>@endif</a>
                @endif
            @endforeach
        @endif
    </div>
</li>
