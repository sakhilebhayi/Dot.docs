<x-action-section>
    <x-slot name="title">
        {{ __('Delete Account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Permanently delete your account.') }}
    </x-slot>

    <x-slot name="content">
        <p class="field-hint">
            {{ __('Documents you own in a company team pass to that team\'s owner and stay where they are. Your administrator can recover your personal documents for 30 days, then they are deleted for good. Your comments and edits on colleagues\' documents stay, shown as "Former member". If you own a team that others use, another member must become its owner first.') }}
        </p>

        <div class="toolbar">
            <x-danger-button wire:click="confirmUserDeletion" wire:loading.attr="disabled">
                {{ __('Delete Account') }}
            </x-danger-button>
        </div>

        <x-dialog-modal wire:model.live="confirmingUserDeletion">
            <x-slot name="title">
                {{ __('Delete Account') }}
            </x-slot>

            <x-slot name="content">
                <p>{{ __('Delete your account? Company documents go to each team\'s owner and your personal documents are deleted for good after 30 days. Enter your password to confirm.') }}</p>

                <div class="field-row" x-data="{}" x-on:confirming-delete-user.window="setTimeout(() => $refs.password.focus(), 250)">
                    <x-label for="delete-account-password" value="{{ __('Password') }}" />
                    <x-input id="delete-account-password"
                             type="password"
                             autocomplete="current-password"
                             x-ref="password"
                             wire:model="password"
                             wire:keydown.enter="deleteUser" />

                    <x-input-error for="password" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button wire:click="deleteUser" wire:loading.attr="disabled">
                    {{ __('Delete Account') }}
                </x-danger-button>
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>
