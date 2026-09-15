<div class="stack">
    <x-form-section submit="createApiToken">
        <x-slot name="title">
            {{ __('Create API Token') }}
        </x-slot>

        <x-slot name="description">
            {{ __('API tokens allow third-party services to authenticate with our application on your behalf.') }}
        </x-slot>

        <x-slot name="form">
            <div class="field-row">
                <x-label for="name" value="{{ __('Token Name') }}" />
                <x-input id="name" type="text" wire:model="createApiTokenForm.name" autofocus />
                <x-input-error for="name" />
            </div>

            @if (Laravel\Jetstream\Jetstream::hasPermissions())
                <div class="field-row">
                    <span class="field-label" id="create-token-permissions">{{ __('Permissions') }}</span>

                    <div class="grid-2" role="group" aria-labelledby="create-token-permissions">
                        @foreach (Laravel\Jetstream\Jetstream::$permissions as $permission)
                            <label class="field-check">
                                <x-checkbox wire:model="createApiTokenForm.permissions" :value="$permission" />
                                <span>{{ $permission }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-slot>

        <x-slot name="actions">
            <x-action-message on="created">
                {{ __('Created.') }}
            </x-action-message>

            <x-button>
                {{ __('Create') }}
            </x-button>
        </x-slot>
    </x-form-section>

    @if ($this->user->tokens->isNotEmpty())
        <x-section-border />

        <x-action-section>
            <x-slot name="title">
                {{ __('Manage API Tokens') }}
            </x-slot>

            <x-slot name="description">
                {{ __('You may delete any of your existing tokens if they are no longer needed.') }}
            </x-slot>

            <x-slot name="content">
                <ul class="list">
                    @foreach ($this->user->tokens->sortBy('name') as $token)
                        <li class="list-row">
                            <span class="list-key">
                                {{ $token->name }}
                                @if ($token->last_used_at)
                                    <span class="list-sub">{{ __('Last used') }} {{ $token->last_used_at->diffForHumans() }}</span>
                                @else
                                    <span class="list-sub">{{ __('Never used') }}</span>
                                @endif
                            </span>

                            @if (Laravel\Jetstream\Jetstream::hasPermissions())
                                <button type="button" class="btn btn-sm" wire:click="manageApiTokenPermissions({{ $token->id }})">
                                    {{ __('Permissions') }}
                                </button>
                            @endif

                            <button type="button" class="btn btn-sm btn-danger" wire:click="confirmApiTokenDeletion({{ $token->id }})">
                                {{ __('Delete') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    <x-dialog-modal wire:model.live="displayingToken">
        <x-slot name="title">
            {{ __('API Token') }}
        </x-slot>

        <x-slot name="content">
            <p>{{ __('Please copy your new API token. For your security, it won\'t be shown again.') }}</p>

            <div class="field-row">
                <label class="field-label" for="plaintext-token">{{ __('API Token') }}</label>
                <input id="plaintext-token" x-ref="plaintextToken" type="text" readonly value="{{ $plainTextToken }}"
                    class="field field-mono"
                    autofocus autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                    @showing-token-modal.window="setTimeout(() => $refs.plaintextToken.select(), 250)" />
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('displayingToken', false)" wire:loading.attr="disabled">
                {{ __('Close') }}
            </x-secondary-button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model.live="managingApiTokenPermissions">
        <x-slot name="title">
            {{ __('API Token Permissions') }}
        </x-slot>

        <x-slot name="content">
            <div class="grid-2" role="group" aria-label="{{ __('Permissions') }}">
                @foreach (Laravel\Jetstream\Jetstream::$permissions as $permission)
                    <label class="field-check">
                        <x-checkbox wire:model="updateApiTokenForm.permissions" :value="$permission" />
                        <span>{{ $permission }}</span>
                    </label>
                @endforeach
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('managingApiTokenPermissions', false)" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button wire:click="updateApiToken" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <x-confirmation-modal wire:model.live="confirmingApiTokenDeletion">
        <x-slot name="title">
            {{ __('Delete API Token') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to delete this API token?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingApiTokenDeletion')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="deleteApiToken" wire:loading.attr="disabled">
                {{ __('Delete') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
