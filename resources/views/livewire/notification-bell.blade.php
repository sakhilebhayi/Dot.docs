{{-- The panel opens as a SHEET, not as a dropdown inside the rail: the rail is
     260px wide and scrolls, so a 320px menu anchored inside it was clipped on
     both axes. A sheet is position:fixed and escapes the rail entirely. --}}
<div x-data="{ open: @entangle('open').live }">
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

    <div x-show="open" x-cloak class="scrim"
         x-on:keydown.escape.window="open = false; $wire.open = false"
         @click.self="open = false; $wire.open = false">
        <div class="sheet" x-trap.inert.noscroll="open" role="dialog" aria-modal="true"
             aria-labelledby="notifications-title" tabindex="-1">
            <div class="sheet-head">
                <h2 class="h-panel" id="notifications-title">Notifications</h2>
                @if ($unreadCount > 0)
                    <button type="button" class="btn btn-quiet btn-sm" wire:click="markAllRead">Mark all read</button>
                @endif
            </div>

            <ul class="ledger sheet-body">
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

            <div class="sheet-foot">
                <button type="button" class="btn" x-on:click="open = false; $wire.open = false">Close</button>
            </div>
        </div>
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
