<div x-data="{ open: @entangle('open').live }" class="menu">
    {{-- The count is a mono readout beside the word, not a coloured pip. --}}
    <button type="button" @click="$wire.toggle()" class="btn btn-quiet btn-sm"
            :aria-expanded="open ? 'true' : 'false'">
        <span class="lamp {{ $unreadCount > 0 ? 'lamp-signal' : 'lamp-idle' }}" aria-hidden="true"></span>
        Notifications
        @if ($unreadCount > 0)
            <span class="readout">
                <x-shell.figure :value="$unreadCount" :width="2" label="Unread notifications" />
            </span>
        @endif
    </button>

    <div x-show="open" @click.outside="open = false; $wire.open = false" x-cloak
         class="menu-list" style="width:320px;left:0;right:auto">
        <div class="panel-head" style="border-bottom:1px solid var(--rule)">
            <h3 class="section-title">Notifications</h3>
            @if ($unreadCount > 0)
                <button type="button" class="btn btn-quiet btn-sm" wire:click="markAllRead">Mark all read</button>
            @endif
        </div>

        <ul class="ledger" style="max-height:288px;overflow-y:auto">
            @forelse ($notifications as $notification)
                <li class="ledger-row">
                    <span class="lamp {{ $notification['read'] ? 'lamp-idle' : 'lamp-signal' }}" aria-hidden="true"></span>
                    <span class="ledger-key">
                        @if ($notification['url'])
                            <a href="{{ $notification['url'] }}" class="link"
                               wire:click="markRead('{{ $notification['id'] }}')">{{ $notification['message'] }}</a>
                        @else
                            {{ $notification['message'] }}
                        @endif
                        <span class="ledger-sub">{{ $notification['time'] }}</span>
                    </span>
                    @if (! $notification['read'])
                        <button type="button" class="btn btn-quiet btn-sm"
                                wire:click="markRead('{{ $notification['id'] }}')">Read</button>
                    @endif
                </li>
            @empty
                <li class="empty">
                    <p class="empty-line">Nothing has come in yet.</p>
                </li>
            @endforelse
        </ul>
    </div>

    {{-- Real-time: listen on the private user channel for new notifications. --}}
    @script
    <script>
        if (typeof window.Echo !== 'undefined') {
            window.Echo.private('App.Models.User.{{ auth()->id() }}')
                .notification(() => {
                    $wire.dispatch('notification-received');
                });
        }
    </script>
    @endscript
</div>
