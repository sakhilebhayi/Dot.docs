{{-- The bell is dot-ui's panel bell inside this Livewire component. dot-ui owns the open state (click, Escape, outside click,
     edge flip); resources/js/shell.js puts it back after a Livewire morph, which would otherwise close it on every commit
     (mark as read). The root is `display: contents` so the bar's own layout sees the bell, not a wrapper. --}}
<div class="shell-notif" data-shell-notif>
    <x-dot.bell :count="$unreadCount" id="notifications-panel" panel-label="Notifications">
        <div class="dh-panel__head">
            <span class="dh-panel__title">Notifications</span>
            @if ($unreadCount > 0)
                <button type="button" class="dh-mark" wire:click="markAllRead">Mark all read</button>
            @endif
        </div>

        <ul class="dh-panel__list">
            @forelse ($notifications as $notification)
                <li class="dh-panel__item" @if (! $notification['read']) data-unread @endif>
                    @if ($notification['url'])
                        <a href="{{ $notification['url'] }}"
                           @click="if (!$event.metaKey && !$event.ctrlKey && !$event.shiftKey && $event.button === 0) { $event.preventDefault(); $wire.openNotice('{{ $notification['id'] }}') }">{{ $notification['message'] }}</a>
                    @else
                        <span>{{ $notification['message'] }}</span>
                    @endif
                    <span class="shell-notif-time">{{ $notification['time'] }}</span>
                    @if (! $notification['read'])
                        <button type="button" class="dh-mark" wire:click="markRead('{{ $notification['id'] }}')">Mark read</button>
                    @endif
                </li>
            @empty
                <li class="dh-panel__empty">Nothing has come in yet.</li>
            @endforelse
        </ul>
    </x-dot.bell>

    {{-- Real-time: listen on the private user channel for new notifications. window.Echo loads after
         the page when a build has a Reverb key (resources/js/bootstrap.js), so it waits rather than give up.
         The script must START with `if` (leading whitespace allowed): Alpine reads anything else, a comment or an indented `const` included, as an expression and the page's next Livewire request fails. --}}
    @script
    <script>
        if (typeof window.Echo !== 'undefined') {
            window.Echo.private('App.Models.User.{{ auth()->id() }}')
                .notification(() => $wire.dispatch('notification-received'));
        } else {
            window.addEventListener('echo:ready', () => window.Echo.private('App.Models.User.{{ auth()->id() }}')
                .notification(() => $wire.dispatch('notification-received')), { once: true });
        }
    </script>
    @endscript
</div>
