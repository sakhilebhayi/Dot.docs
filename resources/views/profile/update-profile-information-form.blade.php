<x-form-section submit="updateProfileInformation">
    <x-slot name="title">
        {{ __('Profile Information') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Update your account\'s profile information and email address.') }}
    </x-slot>

    <x-slot name="form">
        @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
            <div class="field-row" x-data="{photoName: null, photoPreview: null}">
                <input type="file" id="photo" class="sr-only"
                            wire:model.live="photo"
                            x-ref="photo"
                            x-on:change="
                                    photoName = $refs.photo.files[0].name;
                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        photoPreview = e.target.result;
                                    };
                                    reader.readAsDataURL($refs.photo.files[0]);
                            " />

                <x-label for="photo" value="{{ __('Photo') }}" />

                <div class="toolbar">
                    <span class="face-plate" x-show="! photoPreview">
                        <img src="{{ $this->user->profile_photo_url }}" alt="{{ $this->user->name }}">
                    </span>

                    <span class="face-plate" x-show="photoPreview" style="display: none;"
                          x-bind:style="'background-image: url(\'' + photoPreview + '\');'"></span>

                    <x-secondary-button type="button" x-on:click.prevent="$refs.photo.click()">
                        {{ __('Select A New Photo') }}
                    </x-secondary-button>

                    @if ($this->user->profile_photo_path)
                        <x-secondary-button type="button" wire:click="deleteProfilePhoto">
                            {{ __('Remove Photo') }}
                        </x-secondary-button>
                    @endif
                </div>

                <x-input-error for="photo" />
            </div>
        @endif

        <div class="field-row">
            <x-label for="name" value="{{ __('Name') }}" />
            <x-input id="name" type="text" wire:model="state.name" required autocomplete="name" />
            <x-input-error for="name" />
        </div>

        <div class="field-row">
            <x-label for="email" value="{{ __('Email') }}" />
            <x-input id="email" type="email" wire:model="state.email" required autocomplete="username" />
            <x-input-error for="email" />

            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::emailVerification()) && ! $this->user->hasVerifiedEmail())
                <p class="field-hint">
                    {{ __('Your email address is unverified.') }}

                    <button type="button" class="link" wire:click.prevent="sendEmailVerification">
                        {{ __('Click here to re-send the verification email.') }}
                    </button>
                </p>

                @if ($this->verificationLinkSent)
                    <p class="field-hint">
                        <x-shell.status-word tone="good" word="{{ __('Sent') }}" />
                        {{ __('A new verification link has been sent to your email address.') }}
                    </p>
                @endif
            @endif
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message on="saved">
            {{ __('Saved.') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled" wire:target="photo">
            {{ __('Save') }}
        </x-button>
    </x-slot>
</x-form-section>
