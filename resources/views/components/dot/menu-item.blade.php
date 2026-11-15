{{--
  <x-dot.menu-item>: one row of the account menu (dropdown and drawer). <x-dot.account-menu :links="[...]"> prints it for every entry; use it directly
  only inside your own menu markup. Entry shapes (0.6.0 added `form` and `button`):
    ['rule' => true]                                          a divider
    ['heading' => 'Teams']                                    a small group heading
    ['label' => 'Profile', 'href' => '/profile', 'badge' => 3, 'current' => false, 'attrs' => []]   a link
    ['label' => 'Acme', 'current' => true, 'form' => ['action' => '/current-team', 'method' => 'PUT', 'fields' => ['team_id' => 7]]]
                                                              a one-button FORM (POST, PUT, PATCH, DELETE; the method is spoofed with _method, the CSRF token is added
                                                              unless 'csrf' => false). `current` shows a check mark and aria-current="true".
    ['label' => 'Mark all read', 'button' => true, 'attrs' => ['wire:click' => 'markAllRead']]       a plain <button type="button"> for the host's own handler
  Props
    item    the entry
    csrf    the CSRF token (default csrf_token() when Laravel is present)
--}}
@props(['item' => [], 'csrf' => null])
@php
    $token = $csrf ?? (function_exists('csrf_token') ? csrf_token() : null);
    $attrs = new \Illuminate\View\ComponentAttributeBag($item['attrs'] ?? []);
    $badge = isset($item['badge']) && $item['badge'] !== '' && $item['badge'] !== null ? $item['badge'] : null;
    $form = is_array($item['form'] ?? null) ? $item['form'] : null;
    $verb = $form ? strtoupper((string) ($form['method'] ?? 'POST')) : null;
    $spoof = $verb && ! in_array($verb, ['GET', 'POST'], true);
    $current = ! empty($item['current']);
@endphp
@if (! empty($item['rule']))
    <div class="dh-menu__rule" role="separator"></div>
@elseif (isset($item['heading']) && ! isset($item['href']) && ! $form && empty($item['button']))
    <p class="dh-menu__head">{{ $item['heading'] }}</p>
@elseif ($form)
    <form class="dh-menu__form" method="{{ $verb === 'GET' ? 'get' : 'post' }}" action="{{ $form['action'] ?? '' }}">
        @if($verb !== 'GET' && $token && ($form['csrf'] ?? true))<input type="hidden" name="_token" value="{{ $token }}">@endif
        @if($spoof)<input type="hidden" name="_method" value="{{ $verb }}">@endif
        @foreach ((array) ($form['fields'] ?? []) as $fieldName => $fieldValue)
            <input type="hidden" name="{{ $fieldName }}" value="{{ $fieldValue }}">
        @endforeach
        <button type="submit" {{ $attrs->class(['dh-menu__item']) }} @if($current) aria-current="true" @endif><span class="dh-menu__label">{{ $item['label'] ?? '' }}</span>@if($current)<svg class="dh-menu__mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>@endif @if($badge !== null)<span class="dh-count">{{ $badge }}</span>@endif</button>
    </form>
@elseif (! empty($item['button']))
    <button type="button" {{ $attrs->class(['dh-menu__item']) }} @if($current) aria-current="true" @endif><span class="dh-menu__label">{{ $item['label'] ?? '' }}</span>@if($current)<svg class="dh-menu__mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>@endif @if($badge !== null)<span class="dh-count">{{ $badge }}</span>@endif</button>
@else
    <a class="dh-menu__item" href="{{ $item['href'] ?? '#' }}" @if($current) aria-current="page" @endif {{ $attrs }}><span class="dh-menu__label">{{ $item['label'] ?? '' }}</span>@if($badge !== null)<span class="dh-count">{{ $badge }}</span>@endif</a>
@endif
