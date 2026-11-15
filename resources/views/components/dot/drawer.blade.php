{{--
  <x-dot.drawer>: the mobile menu drawer. <x-dot.header> prints it right after the header; use this directly only for a custom shell.
  A fixed overlay on the viewport (not inside the header, never inside a transformed element): `inset: 0` scrim as a sibling of the panel,
  role=dialog + aria-modal, focus trap, Escape, link click closes, scroll lock (js/dot-nav.js). Grouped sections use buttons with
  aria-expanded (no <details>). Closed state is [hidden].

  Props
    id        the drawer id the menu button's aria-controls names (default dh-drawer)
    nav       the same list the header takes: ['label','href','current','badge','attrs'] or a group ['label','items'=>[...],'open'=>bool]
    title     the dialog's name and head text (default "Menu")
    side      'right' (default, under the thumb that holds the menu button) or 'left'
    context   optional text under the head (the company / team name)
    close-label  accessible name of the close button (default "Close menu")
    account   null, or the props of <x-dot.account-menu> as an array: the expanded account section (links, theme, sign out) is printed at the bottom
    guest     null, or ['login' => [...], 'register' => [...]] (0.6.0): the signed-out pair, two full-width rows at the TOP of the drawer, above the nav
    guest-slot  your own markup for the same place
  Slots
    default   replaces the nav sections (your own markup; use .dh-dnav__link / .dh-dgroup)
    before    markup above the nav (a switcher shortcut, a call to action)
    after     markup below the account section
--}}
@props([
    'id' => 'dh-drawer',
    'nav' => [],
    'title' => 'Menu',
    'side' => 'right',
    'context' => null,
    'closeLabel' => 'Close menu',
    'account' => null,
    'guest' => null,
    'guestSlot' => null,
    'before' => null,
    'after' => null,
])
@php
    $gid = 0;
    $customNav = isset($slot) && trim((string) $slot) !== '';
    $hasGuestSlot = $guestSlot !== null && trim((string) $guestSlot) !== '';
    $navList = array_values(array_filter((array) $nav, fn ($i) => ! empty($i['items']) || isset($i['href'])));
@endphp
<div class="dh-drawer" id="{{ $id }}" data-dh-drawer data-side="{{ $side }}" hidden>
    <div class="dh-drawer__scrim" data-dh-scrim aria-hidden="true"></div>
    <div class="dh-drawer__panel" role="dialog" aria-modal="true" aria-label="{{ $title }}" tabindex="-1">
        <div class="dh-drawer__head">
            <span class="dh-drawer__title">{{ $title }}</span>
            <button type="button" class="dh-icon-btn" data-dh-close aria-label="{{ $closeLabel }}"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <div class="dh-drawer__body">
            @if($context)<p class="dh-drawer__context">{{ $context }}</p>@endif
            @if($hasGuestSlot){{ $guestSlot }}@elseif(is_array($guest))<x-dot.guest :inline="true" :login="$guest['login'] ?? null" :register="$guest['register'] ?? null" />@endif
            @if(isset($before) && trim((string) $before) !== ''){{ $before }}@endif
            @if ($customNav)
                {{ $slot }}
            @elseif (count($navList) > 0)
                <nav class="dh-dnav" aria-label="{{ $title }}">
                    <ul>
                        @foreach ((array) $nav as $item)
                            @if (! empty($item['items']))
                                @php
                                    $gid++;
                                    $listId = $id.'-g'.$gid;
                                    $open = ! empty($item['open']) || collect($item['items'])->contains(fn ($i) => ! empty($i['current']));
                                @endphp
                                <li class="dh-dgroup">
                                    <button type="button" class="dh-dgroup__btn" data-dh-disclosure aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $listId }}"><span class="dh-menu__label">{{ $item['label'] }}</span><span class="dh-chev" aria-hidden="true"></span></button>
                                    <ul id="{{ $listId }}" class="dh-dgroup__list" @unless($open) hidden @endunless>
                                        @foreach ($item['items'] as $sub)
                                            @if (isset($sub['href']))
                                                <li><a class="dh-dnav__link" href="{{ $sub['href'] }}" @if(! empty($sub['current'])) aria-current="page" @endif {{ new \Illuminate\View\ComponentAttributeBag($sub['attrs'] ?? []) }}><span class="dh-menu__label">{{ $sub['label'] ?? '' }}</span>@if(isset($sub['badge']) && $sub['badge'] !== '' && $sub['badge'] !== null)<span class="dh-count">{{ $sub['badge'] }}</span>@endif</a></li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </li>
                            @elseif (isset($item['href']))
                                <li><a class="dh-dnav__link" href="{{ $item['href'] }}" @if(! empty($item['current'])) aria-current="page" @endif {{ new \Illuminate\View\ComponentAttributeBag($item['attrs'] ?? []) }}><span class="dh-menu__label">{{ $item['label'] ?? '' }}</span>@if(isset($item['badge']) && $item['badge'] !== '' && $item['badge'] !== null)<span class="dh-count">{{ $item['badge'] }}</span>@endif</a></li>
                            @endif
                        @endforeach
                    </ul>
                </nav>
            @endif
            @if (is_array($account))
                <x-dot.account-menu :inline="true" :name="$account['name'] ?? ''" :email="$account['email'] ?? null" :team="$account['team'] ?? null" :initials="$account['initials'] ?? null" :avatar="$account['avatar'] ?? null" :links="$account['links'] ?? []" :sign-out="$account['signOut'] ?? null" :csrf="$account['csrf'] ?? null" :theme="! empty($account['theme'])" />
            @endif
            @if(isset($after) && trim((string) $after) !== ''){{ $after }}@endif
        </div>
    </div>
</div>
