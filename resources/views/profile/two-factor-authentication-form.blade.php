<x-action-section>
    <x-slot name="title">
        {{ __('Two Factor Authentication') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Add additional security to your account using two factor authentication.') }}
    </x-slot>

    <x-slot name="content">
        {{-- State is a WORD and a dot, never a colour on its own. --}}
        <x-shell.status-word :tone="$this->enabled ? 'good' : 'idle'"
                             :word="$this->enabled ? __('On') : __('Off')" />

        <h3 class="h-panel">
            @if ($this->enabled)
                @if ($showingConfirmation)
                    {{ __('Finish enabling two factor authentication.') }}
                @else
                    {{ __('You have enabled two factor authentication.') }}
                @endif
            @else
                {{ __('You have not enabled two factor authentication.') }}
            @endif
        </h3>

        <p class="field-hint">
            {{ __('When two factor authentication is enabled, you will be prompted for a secure, random token during authentication. You may retrieve this token from your phone\'s Google Authenticator application.') }}
        </p>

        @if ($this->enabled)
            @if ($showingQrCode)
                <p class="field-hint">
                    @if ($showingConfirmation)
                        {{ __('To finish enabling two factor authentication, scan the following QR code using your phone\'s authenticator application or enter the setup key and provide the generated OTP code.') }}
                    @else
                        {{ __('Two factor authentication is now enabled. Scan the following QR code using your phone\'s authenticator application or enter the setup key.') }}
                    @endif
                </p>

                <div class="qr-plate">
                    {!! $this->user->twoFactorQrCodeSvg() !!}
                </div>

                <p class="micro">
                    {{ __('Setup Key') }}: {{ decrypt($this->user->two_factor_secret) }}
                </p>

                @if ($showingConfirmation)
                    <div class="field-row">
                        <x-label for="code" value="{{ __('Code') }}" />

                        <x-input id="code" type="text" name="code" inputmode="numeric" autofocus autocomplete="one-time-code"
                            wire:model="code"
                            wire:keydown.enter="confirmTwoFactorAuthentication" />

                        <x-input-error for="code" />
                    </div>
                @endif
            @endif

            @if ($showingRecoveryCodes)
                <p class="field-hint">
                    {{ __('Store these recovery codes in a secure password manager. They can be used to recover access to your account if your two factor authentication device is lost.') }}
                </p>

                <ul class="list">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <li class="list-row"><span class="micro">{{ $code }}</span></li>
                    @endforeach
                </ul>
            @endif
        @endif

        <div class="toolbar">
            @if (! $this->enabled)
                <x-confirms-password wire:then="enableTwoFactorAuthentication">
                    <x-button type="button" wire:loading.attr="disabled">
                        {{ __('Enable') }}
                    </x-button>
                </x-confirms-password>
            @else
                @if ($showingRecoveryCodes)
                    <x-confirms-password wire:then="regenerateRecoveryCodes">
                        <x-secondary-button>
                            {{ __('Regenerate Recovery Codes') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @elseif ($showingConfirmation)
                    <x-confirms-password wire:then="confirmTwoFactorAuthentication">
                        <x-button type="button" wire:loading.attr="disabled">
                            {{ __('Confirm') }}
                        </x-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="showRecoveryCodes">
                        <x-secondary-button>
                            {{ __('Show Recovery Codes') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @endif

                @if ($showingConfirmation)
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-secondary-button wire:loading.attr="disabled">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-danger-button wire:loading.attr="disabled">
                            {{ __('Disable') }}
                        </x-danger-button>
                    </x-confirms-password>
                @endif
            @endif
        </div>
    </x-slot>
</x-action-section>
