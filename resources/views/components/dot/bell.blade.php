{{--
  <x-dot.bell>: the notifications bell for the header's `tools` slot. Two forms:

    LINK (no slot): a 44px link with a count badge; the count is capped ("99+"), the accessible name carries the real number. The platform owns where
      it goes and what the count is. Hidden under 480px, where the drawer carries the same link, unless `always` is set.
    PANEL (content in the slot, 0.6.0): a 44px BUTTON that opens a panel under it, for a platform whose bell is a dropdown of notifications (a Livewire
      component, a fetched list). The panel is a menu root for js/dot-nav.js: it opens on click, closes on Escape / outside click / when another menu opens,
      flips at the screen edge, never opens on hover, and dispatches `dot:menu-open` (bubbling) so a host can load its list lazily. It sits on the panel
      tokens (never the bar's light ink), is `min(380px, 100vw - 16px)` wide, and under 480px a fixed sheet under the header. A panel bell is never hidden:
      there is no page for the drawer to link to. Markup helpers for the content: .dh-panel__head / __title / __list / __item[data-unread] / __empty /
      __foot and the 44px text button .dh-mark ("Mark all read").

  Props
    href    LINK form: where it goes (default '#')
    count   unread number (0, null or '' = no badge)
    label   accessible name stem (default "Notifications"): "Notifications, 12 unread"
    max     the cap shown in the badge (default 99)
    always  LINK form: keep it in the bar under 480px too (the panel form always is)
    id      PANEL form: the panel's id (default dh-bell-panel)
    panel-label  PANEL form: the panel's accessible name (default: the label)
  Other attributes (wire:navigate, data-*, class) land on the <a> (link) or the <button> (panel).
--}}
@props([
    'href' => '#',
    'count' => 0,
    'label' => 'Notifications',
    'max' => 99,
    'always' => false,
    'id' => 'dh-bell-panel',
    'panelLabel' => null,
])
@php
    $n = is_numeric($count) ? (int) $count : 0;
    $shown = $n > (int) $max ? $max.'+' : (string) $n;
    $name = $n > 0 ? $label.', '.$n.' unread' : $label;
    $hasPanel = isset($slot) && trim((string) $slot) !== '';
@endphp
@if ($hasPanel)
<div class="dh-bellwrap" data-dh-menu data-dh-bell>
    <button type="button" {{ $attributes->class(['dh-icon-btn', 'dh-bell', 'dh-bell--panel']) }} data-dh-menu-trigger aria-expanded="false" aria-controls="{{ $id }}" aria-haspopup="true" aria-label="{{ $name }}">
        <svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4"/></svg>
        @if ($n > 0)<span class="dh-badge" aria-hidden="true">{{ $shown }}</span>@endif
    </button>
    <div id="{{ $id }}" class="dh-menu dh-menu--end dh-bellpanel" role="region" aria-label="{{ $panelLabel ?? $label }}" data-dh-menu-panel hidden>{{ $slot }}</div>
</div>
@else
<a {{ $attributes->class(['dh-icon-btn', 'dh-bell', 'dh-bell--always' => $always])->merge(['href' => $href]) }} aria-label="{{ $name }}">
    <svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4"/></svg>
    @if ($n > 0)<span class="dh-badge" aria-hidden="true">{{ $shown }}</span>@endif
</a>
@endif
