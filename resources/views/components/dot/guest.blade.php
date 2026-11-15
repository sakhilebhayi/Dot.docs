{{--
  <x-dot.guest>: the signed-out state of the header (0.6.0): "Log in" and "Create account" in place of the account menu. <x-dot.header :guest="[...]">
  prints it for you (in the bar from 861px, at the top of the drawer on a phone, `inline`); use it directly only for a custom shell.
  Props
    login      ['label' => 'Log in', 'href' => '/login', 'attrs' => []]            (null: no login link)
    register   ['label' => 'Create account', 'href' => '/register', 'attrs' => []]  (null: no register link; this one is the primary button)
    inline     true: the drawer's two stacked full-width rows
    label      accessible name of the group (default "Account")
--}}
@props([
    'login' => null,
    'register' => null,
    'inline' => false,
    'label' => 'Account',
])
@php
    $one = fn ($item, $default) => is_array($item) ? $item + ['label' => $default, 'href' => '#', 'attrs' => []] : null;
    $in = $one($login, 'Log in');
    $up = $one($register, 'Create account');
@endphp
@if ($in || $up)
<div {{ $attributes->class([$inline ? 'dh-dguest' : 'dh-guest']) }} role="group" aria-label="{{ $label }}">
    @if ($in)<a class="dh-guest__link" href="{{ $in['href'] }}" {{ new \Illuminate\View\ComponentAttributeBag($in['attrs']) }}>{{ $in['label'] }}</a>@endif
    @if ($up)<a class="dh-guest__link dh-guest__link--primary" href="{{ $up['href'] }}" {{ new \Illuminate\View\ComponentAttributeBag($up['attrs']) }}>{{ $up['label'] }}</a>@endif
</div>
@endif
