{{--
  <x-dot.account-menu>: the avatar button and its menu (account links, sign out, theme). The same component prints the expanded list
  inside the drawer (`inline`). A disclosure (button + panel); never opens on hover; Escape returns focus to the button.

  Props
    name      the person's name (printed in the menu head; truncates, full text in the title)
    email     optional, under the name
    team      optional, the company / team name, under the email
    initials  the avatar letters (default: the first letters of `name`)
    avatar    optional image URL (replaces the initials)
    links     list of ['label' => 'Profile', 'href' => '/profile', 'badge' => null, 'attrs' => [], 'rule' => false]
              (an entry with only 'rule' => true prints a divider). 0.6.0: an entry may also be a FORM row (`form` => ['action', 'method' => 'PUT',
              'fields' => [...]], printed with the CSRF token and a spoofed method: Jetstream's team switch), a plain BUTTON row (`button` => true, `attrs`
              for wire:click) or a heading; see blade/menu-item.blade.php.
    sign-out  ['action' => '/logout', 'method' => 'post', 'label' => 'Sign out'] (null: no sign-out row). A POST form with the
              CSRF token (csrf_token() when Laravel is present, or `csrf`).
    csrf      override the CSRF token (non-Laravel hosts)
    theme     true: Light / Dark / System buttons ([data-dh-theme-set], js sets data-theme on <html> and fires dot:theme).
              Only for platforms that really have a dark theme.
    inline    true: print the expanded list for the drawer instead of the dropdown
    id        panel id (default dh-account-menu)
    label     accessible name of the button stem (default "Account menu")
--}}
@props([
    'name' => '',
    'email' => null,
    'team' => null,
    'initials' => null,
    'avatar' => null,
    'links' => [],
    'signOut' => null,
    'csrf' => null,
    'theme' => false,
    'inline' => false,
    'id' => 'dh-account-menu',
    'label' => 'Account menu',
])
@php
    $letters = $initials ?: collect(preg_split('/\s+/', trim((string) $name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
    $token = $csrf ?? (function_exists('csrf_token') ? csrf_token() : null);
    $out = is_array($signOut) ? $signOut + ['method' => 'post', 'label' => 'Sign out'] : null;
@endphp
@if (! $inline)
<div {{ $attributes->class(['dh-account']) }} data-dh-menu data-dh-account>
    <button type="button" class="dh-account__btn" data-dh-menu-trigger aria-expanded="false" aria-controls="{{ $id }}" aria-label="{{ $name ? $label.': '.$name : $label }}">
        <span class="dh-avatar" aria-hidden="true">@if($avatar)<img src="{{ $avatar }}" alt="" width="34" height="34">@else{{ $letters }}@endif</span>
    </button>
    <div id="{{ $id }}" class="dh-menu dh-menu--end" data-dh-menu-panel hidden>
        <div class="dh-menu__who">
            <span class="dh-menu__name" title="{{ $name }}">{{ $name }}</span>
            @if($email)<span class="dh-menu__sub" title="{{ $email }}">{{ $email }}</span>@endif
            @if($team)<span class="dh-menu__sub" title="{{ $team }}">{{ $team }}</span>@endif
        </div>
        <div class="dh-menu__rule" role="separator"></div>
        @foreach ((array) $links as $link)
            <x-dot.menu-item :item="$link" :csrf="$token" />
        @endforeach
        @if ($theme)
            <div class="dh-menu__rule" role="separator"></div>
            <div class="dh-theme" role="group" aria-label="Theme">
                <button type="button" class="dh-theme__btn" data-dh-theme-set="light" aria-pressed="false">Light</button>
                <button type="button" class="dh-theme__btn" data-dh-theme-set="dark" aria-pressed="false">Dark</button>
                <button type="button" class="dh-theme__btn" data-dh-theme-set="system" aria-pressed="false">System</button>
            </div>
        @endif
        @if ($out)
            <div class="dh-menu__rule" role="separator"></div>
            <form class="dh-signout" method="{{ strtolower($out['method']) === 'get' ? 'get' : 'post' }}" action="{{ $out['action'] }}">
                @if($token && strtolower($out['method']) !== 'get')<input type="hidden" name="_token" value="{{ $token }}">@endif
                <button type="submit" class="dh-menu__item">{{ $out['label'] }}</button>
            </form>
        @endif
    </div>
</div>
@else
<section {{ $attributes->class(['dh-dacct']) }} aria-label="Account">
    <div class="dh-dacct__who">
        <span class="dh-avatar" aria-hidden="true">@if($avatar)<img src="{{ $avatar }}" alt="" width="34" height="34">@else{{ $letters }}@endif</span>
        <span>
            <span class="dh-menu__name" title="{{ $name }}">{{ $name }}</span>
            @if($email)<span class="dh-menu__sub" title="{{ $email }}">{{ $email }}</span>@endif
            @if($team)<span class="dh-menu__sub" title="{{ $team }}">{{ $team }}</span>@endif
        </span>
    </div>
    @foreach ((array) $links as $link)
        <x-dot.menu-item :item="$link" :csrf="$token" />
    @endforeach
    @if ($theme)
        <div class="dh-theme" role="group" aria-label="Theme">
            <button type="button" class="dh-theme__btn" data-dh-theme-set="light" aria-pressed="false">Light</button>
            <button type="button" class="dh-theme__btn" data-dh-theme-set="dark" aria-pressed="false">Dark</button>
            <button type="button" class="dh-theme__btn" data-dh-theme-set="system" aria-pressed="false">System</button>
        </div>
    @endif
    @if ($out)
        <form class="dh-signout" method="{{ strtolower($out['method']) === 'get' ? 'get' : 'post' }}" action="{{ $out['action'] }}">
            @if($token && strtolower($out['method']) !== 'get')<input type="hidden" name="_token" value="{{ $token }}">@endif
            <button type="submit" class="dh-menu__item">{{ $out['label'] }}</button>
        </form>
    @endif
</section>
@endif
