{{--
  <x-dot.header>: the shared Dot top bar. One row, a CSS Grid with three columns (`minmax(0,1fr) minmax(280px,640px) minmax(0,1fr)`), so
  the search is centred in the VIEWPORT whatever the left and right hold. The platform keeps its own logo and identity (the `brand`
  slot); dot-ui provides the structure, the behaviour and the mobile pattern. docs/HEADER.md is the adoption guide.

  Props
    brand-href     where the logo goes (default '/')
    brand-label    the logo link's accessible name (default "Home")
    nav            primary items: ['label','href','current','badge','attrs'] or a group ['label','items'=>[...]] (a dropdown on desktop,
                   a section in the drawer). Items that do not fit the bar move into "More" (js).
    nav-label      aria nav name (default "Primary")
    more-label     the overflow button text (default "More")
    account        props of <x-dot.account-menu> as an array (name, email, team, initials, avatar, links, signOut, theme), or null
    drawer-id      drawer id (default dh-drawer). With `drawer-target` set no drawer is printed
    drawer-target  id of the PLATFORM's own nav element (a sidebar shell): the menu button opens THAT, js adds data-dh-open to it, a scrim on
                   <body>, scroll lock, focus trap, Escape, link-click closing. The platform's CSS slides it on [data-dh-open].
    drawer-side    'right' (default) or 'left'
    drawer-title   the drawer's name (default "Menu")
    menu-label     the hamburger's accessible name (default "Menu")
    search-label   the mobile search button's accessible name (default "Search")
    close-search-label  the sheet's back button (default "Close search")
    context        shorthand for the context slot as plain text (a company name)
    skip           href of a skip link (e.g. '#main'); null prints none
    sticky         false: no position:sticky (a shell that positions the header itself)
    guest          signed-out state (0.6.0): ['login' => ['label','href','attrs'], 'register' => [...]] prints "Log in" / "Create account" instead of the account
                   menu (bar from 861px, top of the drawer on a phone). Or the `guest` slot for your own markup.
  Slots
    brand       the logo (an <img> or <svg>, optionally a .dh-brand__name span). Printed inside <a class="dh-brand">.
    context     a short company/team name (wide screens only; the full name is always in the account menu and the drawer)
    nav         replaces the generated primary nav: your own <li class="dh-nav__item"> items (and <x-dot.nav-menu> groups)
    search      the search: <x-dot.search ...> untouched. Without it the header has no centre column (`dh--nosearch`).
    status      an always-visible readout beside the brand (a state lamp and word, counts): never hidden at any width, truncates instead (0.6.0)
    tools       before the account menu: <x-dot.switcher compact>, <x-dot.bell>
    account     replaces <x-dot.account-menu> (your own markup)
    guest       replaces the Log in / Create account pair (your own markup; the drawer gets the same markup unless `drawer-guest` is given)
    drawer      replaces the drawer's nav sections
    drawer-before / drawer-after   extra drawer markup above the nav / below the account section
  Needs css/dot-header.css (+ the search and switcher css) and js/dot-nav.js (+ js/dot-search.js, js/dot-switcher.js).
--}}
@props([
    'brandHref' => '/',
    'brandLabel' => 'Home',
    'nav' => [],
    'navLabel' => 'Primary',
    'moreLabel' => 'More',
    'account' => null,
    'guest' => null,
    'drawerId' => 'dh-drawer',
    'drawerTarget' => null,
    'drawerSide' => 'right',
    'drawerTitle' => 'Menu',
    'menuLabel' => 'Menu',
    'searchLabel' => 'Search',
    'closeSearchLabel' => 'Close search',
    'context' => null,
    'skip' => null,
    'sticky' => true,
    'id' => 'dh',
])
@php
    // a prop and a slot may share a name (nav, account, context): an array/string is the prop, a slot object is the slot
    $blank = fn ($v) => $v === null || $v === false || (is_string($v) ? trim($v) === '' : (! is_array($v) && trim((string) $v) === ''));
    $hasSearch = ! $blank($search ?? null);
    $navSlot = (! is_array($nav ?? null) && ! $blank($nav ?? null)) ? $nav : null;
    $navItems = is_array($nav ?? null) ? $nav : [];
    $accountSlot = (! is_array($account ?? null) && ! $blank($account ?? null)) ? $account : null;
    $accountData = is_array($account ?? null) ? $account : null;
    $ctx = $blank($context ?? null) ? null : $context;
    $controls = $drawerTarget ?: $drawerId;
    $searchId = $id.'-search';
    $guestSlot = (! is_array($guest ?? null) && ! $blank($guest ?? null)) ? $guest : null;
    $guestData = is_array($guest ?? null) ? $guest : null;
    $hasNav = $navSlot !== null || count($navItems) > 0;
    $hasStatus = ! $blank($status ?? null);
@endphp
<header {{ $attributes->class(['dh', 'dh--nosearch' => ! $hasSearch, 'dh--static' => ! $sticky, 'dh--nonav' => ! $hasNav, 'dh--adopt' => (bool) $drawerTarget]) }} id="{{ $id }}" data-dh>
    @if($skip)<a class="dh-skip skip-link" href="{{ $skip }}">Skip to content</a>@endif
    <div class="dh-bar">
        <div class="dh-start">
            <a class="dh-brand" href="{{ $brandHref }}" aria-label="{{ $brandLabel }}">@isset($brand){{ $brand }}@endisset</a>
            @if($ctx)<div class="dh-context"><span class="dh-context__text" @if(is_string($ctx)) title="{{ $ctx }}" @endif>{{ $ctx }}</span></div>@endif
            @if($hasStatus)<div class="dh-status" data-dh-status>{{ $status }}</div>@endif
            @if($hasNav)
            <nav class="dh-nav" aria-label="{{ $navLabel }}" data-dh-nav>
                <ul class="dh-nav__list">
                    @if ($navSlot)
                        {{ $navSlot }}
                    @else
                        @foreach ($navItems as $item)
                            @if (! empty($item['items']))
                                <x-dot.nav-menu :label="$item['label']" :items="$item['items']" />
                            @elseif (isset($item['href']))
                                <li class="dh-nav__item"><a class="dh-nav__link" href="{{ $item['href'] }}" @if(! empty($item['current'])) aria-current="page" @endif {{ new \Illuminate\View\ComponentAttributeBag($item['attrs'] ?? []) }}>{{ $item['label'] ?? '' }}@if(isset($item['badge']) && $item['badge'] !== '' && $item['badge'] !== null)<span class="dh-count">{{ $item['badge'] }}</span>@endif</a></li>
                            @endif
                        @endforeach
                    @endif
                    <li class="dh-nav__item dh-more" data-dh-menu data-dh-more data-dh-label="{{ $moreLabel }}" hidden>
                        <button type="button" class="dh-nav__link" data-dh-menu-trigger aria-expanded="false" aria-controls="{{ $id }}-more">{{ $moreLabel }}<span class="dh-chev" aria-hidden="true"></span></button>
                        <div id="{{ $id }}-more" class="dh-menu" data-dh-menu-panel hidden>
                            <ul class="dh-more__list" data-dh-more-list></ul>
                        </div>
                    </li>
                </ul>
            </nav>
            @endif
        </div>
        @if ($hasSearch)
            <div class="dh-search" id="{{ $searchId }}" data-dh-search>
                {{ $search }}
                <button type="button" class="dh-icon-btn dh-search__close" data-dh-search-close aria-label="{{ $closeSearchLabel }}"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg></button>
            </div>
        @endif
        <div class="dh-end">
            @if ($hasSearch)
                <button type="button" class="dh-icon-btn dh-search-btn" data-dh-search-toggle aria-expanded="false" aria-controls="{{ $searchId }}" aria-label="{{ $searchLabel }}"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg></button>
            @endif
            @isset($tools){{ $tools }}@endisset
            @if ($accountSlot)
                {{ $accountSlot }}
            @elseif ($accountData)
                <x-dot.account-menu :name="$accountData['name'] ?? ''" :email="$accountData['email'] ?? null" :team="$accountData['team'] ?? null" :initials="$accountData['initials'] ?? null" :avatar="$accountData['avatar'] ?? null" :links="$accountData['links'] ?? []" :sign-out="$accountData['signOut'] ?? null" :csrf="$accountData['csrf'] ?? null" :theme="! empty($accountData['theme'])" />
            @elseif ($guestSlot)
                {{ $guestSlot }}
            @elseif ($guestData)
                <x-dot.guest :login="$guestData['login'] ?? null" :register="$guestData['register'] ?? null" />
            @endif
            <button type="button" class="dh-icon-btn dh-menu-btn" data-dh-menu-button aria-expanded="false" aria-controls="{{ $controls }}" aria-label="{{ $menuLabel }}"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
        </div>
    </div>
</header>
@unless ($drawerTarget)
<x-dot.drawer :id="$drawerId" :nav="$navItems" :title="$drawerTitle" :side="$drawerSide" :context="is_string($ctx) ? $ctx : null" :account="$accountData" :guest="$guestData" :guest-slot="$drawerGuest ?? $guestSlot" :before="$drawerBefore ?? null" :after="$drawerAfter ?? null">@isset($drawer){{ $drawer }}@endisset</x-dot.drawer>
@endunless
