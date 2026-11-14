{{--
  <x-dot.switcher>: the "Dot Apps" platform switcher (InfoDot's look and flow, in every platform). See docs/SWITCHER.md.

  The platform list is read server side from InfoDot for the CURRENT company (Dot\Ui\PlatformSwitcher), cached, and
  fail closed. Every tile links to {infodot}/go/<key>?company=<uuid>: InfoDot decides, mints the single-use handoff
  and posts it to the destination. This view only prints the view model; it decides nothing and holds no secret.

  Props
    platform     this platform's key, e.g. "sheet" (default: config ecosystem.infodot.platform)
    company      the current company's InfoDot uuid; null/blank = personal workspace
    mode         "header" (the only one for now)
    company-name optional, printed in the sentence ("The 8 platforms Acme has")
    logos        public URL path the packaged img/platforms/*.png are served from (default /img/platforms)
    model        optional prebuilt view model (tests, static previews); skips the InfoDot read
    id           the panel's id (default dsw-panel; change it only if a page holds two switchers)
    notice       override of the visible degraded line, e.g. "Could not check your platforms from here" (a platform that
                 structurally cannot ask InfoDot). Default: the model's "Could not check your platforms just now"
    notice-detail  override of the sentence after it. Default: "These open through InfoDot, which checks your access."

  Needs css/dot-switcher.css and, for open/close/filter/"Opening...", js/dot-switcher.js (no inline script, no inline style attributes).
  The nearest positioned ancestor (the header) is where the panel hangs from.
--}}
@props([
    'platform' => null,
    'company' => null,
    'mode' => 'header',
    'companyName' => null,
    'logos' => '/img/platforms',
    'model' => null,
    'id' => 'dsw-panel',
    'notice' => null,
    'noticeDetail' => null,
])
@php
    if ($model === null) {
        try {
            $model = \Dot\Ui\PlatformSwitcher::fromConfig($platform)->for($company);
        } catch (\Throwable $e) {
            $model = null;
        }
    }

    $logoBase = rtrim((string) $logos, '/');
    $state = $model['state'] ?? null;
    $count = $model['count'] ?? 0;
@endphp
@if ($model !== null)
<div {{ $attributes->class(['dsw', 'dsw--'.$mode]) }} data-dsw data-state="{{ $state }}" data-platform="{{ $model['current'] }}">
    <a class="dsw-fallback" href="{{ $model['platforms_url'] }}" title="Switch Dot platform">
        <svg class="dsw-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="10" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="16" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="4" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="10" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="16" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="4" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="10" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="16" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/></svg>
        <span class="dsw-btn__label">Dot Apps</span>
    </a>
    <button type="button" class="dsw-btn" data-dsw-trigger aria-expanded="false" aria-controls="{{ $id }}" title="Switch Dot platform">
        <svg class="dsw-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="10" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="16" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="4" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="10" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="16" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="4" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="10" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/><rect x="16" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none"/></svg>
        <span class="dsw-btn__label">Dot Apps</span>
    </button>

    <div id="{{ $id }}" class="dsw-panel" data-dsw-panel role="region" aria-label="Dot platforms" hidden>
        <div class="dsw-in">
            <div class="dsw-head">
                <div>
                    <p class="dsw-title">Dot Apps</p>
                    <p class="dsw-sub">
                        @if ($state === 'ok')
                            {{ $count === 1 ? 'The one platform' : 'The '.$count.' platforms' }} {{ $companyName ?: 'your company' }} has. Each opens already signed in.
                        @elseif ($state === 'degraded')
                            The core Dot platforms, opened through InfoDot.
                        @else
                            Platforms are switched on per company.
                        @endif
                        <a href="{{ $model['platforms_url'] }}" class="dsw-all">See all platforms</a>
                    </p>
                </div>
                @if ($count > 0)
                    <label class="dsw-filter">
                        <span class="dsw-sr">Filter platforms</span>
                        <svg class="dsw-filter__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                        <input type="search" data-dsw-filter placeholder="Filter platforms" autocomplete="off">
                    </label>
                @endif
            </div>

            @if ($state === 'degraded')
                <p class="dsw-notice" role="status"><strong>{{ $notice ?: $model['notice'] }}.</strong> <span>{{ $noticeDetail ?: 'These open through InfoDot, which checks your access.' }}</span></p>
            @endif

            @if ($state === 'personal')
                <p class="dsw-personal">{{ $model['message'] }}</p>
            @else
                <p class="dsw-empty" data-dsw-empty role="status" hidden>No platform matches &ldquo;<span data-dsw-empty-q></span>&rdquo;.</p>

                <div class="dsw-groups">
                    @foreach ($model['groups'] as $group)
                        <section class="dsw-bays" data-tone="{{ $group['tone'] }}" data-dsw-group>
                            <h3 class="dsw-bays__head"><span class="dsw-bays__code">{{ $group['code'] }}</span><span class="dsw-bays__name">{{ $group['name'] }}</span></h3>
                            <ul class="dsw-bays__grid">
                                @foreach ($group['tiles'] as $tile)
                                    @php
                                        $size = \Dot\Ui\PlatformLogos::size($tile['key']);
                                    @endphp
                                    <li data-dsw-name="{{ mb_strtolower($tile['name']) }}">
                                        @if ($tile['href'] === null)
                                            <div class="dsw-bay is-current" aria-current="page">
                                                <span class="dsw-bay__logo">
                                                    @if ($tile['logo'] && $size)
                                                        <img src="{{ $logoBase }}/{{ $tile['logo'] }}" alt="{{ $tile['name'] }}" width="{{ $size[0] }}" height="{{ $size[1] }}" decoding="async">
                                                    @endif
                                                </span>
                                                <span class="dsw-bay__name">{{ $tile['name'] }}</span>
                                                <span class="dsw-bay__tag dsw-bay__tag--here">You are here</span>
                                            </div>
                                        @else
                                            <a class="dsw-bay" href="{{ $tile['href'] }}" data-dsw-go data-dsw-label="{{ $tile['name'] }}" title="{{ $tile['via_infodot'] ? 'Open '.$tile['name'].' through InfoDot' : 'Open '.$tile['name'] }}">
                                                <span class="dsw-bay__logo">
                                                    @if ($tile['logo'] && $size)
                                                        <img src="{{ $logoBase }}/{{ $tile['logo'] }}" alt="{{ $tile['name'] }}" width="{{ $size[0] }}" height="{{ $size[1] }}" loading="lazy" decoding="async">
                                                    @endif
                                                </span>
                                                <span class="dsw-bay__name">{{ $tile['name'] }}</span>
                                                @if ($tile['plain'])
                                                    <span class="dsw-bay__tag dsw-bay__tag--own" title="Has its own sign-in">Own sign-in</span>
                                                @endif
                                                <span class="dsw-bay__tag dsw-bay__tag--busy" aria-hidden="true">Opening&hellip;</span>
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>

                @if (count($model['more']) > 0)
                    <details class="dsw-more" data-dsw-more>
                        <summary>Not switched on ({{ count($model['more']) }})</summary>
                        <p class="dsw-more__note">Your company has not switched these on. Each link shows what the platform does and how to add it.</p>
                        <ul>
                            @foreach ($model['more'] as $other)
                                <li data-dsw-name="{{ mb_strtolower($other['name']) }}"><a href="{{ $other['href'] }}">{{ $other['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @endif

            <p class="dsw-sr" data-dsw-live role="status" aria-live="polite"></p>
        </div>
    </div>
</div>
@endif
