<x-action-section>
    <x-slot name="title">
        {{ __('Browser Sessions') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Manage and log out your active sessions on other browsers and devices.') }}
    </x-slot>

    <x-slot name="content">
        <p class="field-hint">
            {{ __('If necessary, you may log out of all of your other browser sessions across all of your devices. Some of your recent sessions are listed below; however, this list may not be exhaustive. If you feel your account has been compromised, you should also update your password.') }}
        </p>

        @if (count($this->sessions) > 0)
            <ul class="list">
                @foreach ($this->sessions as $session)
                    <li class="list-row">
                        <span class="list-key">
                            {{ $session->agent->isDesktop() ? __('Desktop') : __('Mobile') }} —
                            {{ $session->agent->platform() ?: __('Unknown') }} — {{ $session->agent->browser() ?: __('Unknown') }}
                            <span class="list-sub">{{ $session->ip_address }}</span>
                        </span>
                        @if ($session->is_current_device)
                            <x-shell.status-word tone="good" word="{{ __('This device') }}" />
                        @else
                            <span class="list-val">{{ __('Last active') }} {{ $session->last_active }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="toolbar">
            <x-button wire:click="confirmLogout" wire:loading.attr="disabled">
                {{ __('Log Out Other Browser Sessions') }}
            </x-button>

            <x-action-message on="loggedOut">
                {{ __('Done.') }}
            </x-action-message>
        </div>

        <x-dialog-modal wire:model.live="confirmingLogout">
            <x-slot name="title">
                {{ __('Log Out Other Browser Sessions') }}
            </x-slot>

            <x-slot name="content">
                <p>{{ __('Please enter your password to confirm you would like to log out of your other browser sessions across all of your devices.') }}</p>

                <div class="field-row" x-data="{}" x-on:confirming-logout-other-browser-sessions.window="setTimeout(() => $refs.password.focus(), 250)">
                    <x-label for="logout-sessions-password" value="{{ __('Password') }}" />
                    <x-input id="logout-sessions-password"
                             type="password"
                             autocomplete="current-password"
                             x-ref="password"
                             wire:model="password"
                             wire:keydown.enter="logoutOtherBrowserSessions" />

                    <x-input-error for="password" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingLogout')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-button wire:click="logoutOtherBrowserSessions" wire:loading.attr="disabled">
                    {{ __('Log Out Other Browser Sessions') }}
                </x-button>
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>
