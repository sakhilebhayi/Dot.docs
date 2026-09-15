<x-form-section submit="createTeam">
    <x-slot name="title">
        {{ __('Team Details') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Create a new team to collaborate with others on projects.') }}
    </x-slot>

    <x-slot name="form">
        <div class="field-row">
            <x-label value="{{ __('Team Owner') }}" />

            <div class="toolbar">
                <span class="face-plate">
                    <img src="{{ $this->user->profile_photo_url }}" alt="{{ $this->user->name }}">
                </span>
                <span class="list-key">
                    {{ $this->user->name }}
                    <span class="list-sub">{{ $this->user->email }}</span>
                </span>
            </div>
        </div>

        <div class="field-row">
            <x-label for="name" value="{{ __('Team Name') }}" />
            <x-input id="name" type="text" wire:model="state.name" autofocus />
            <x-input-error for="name" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-button>
            {{ __('Create') }}
        </x-button>
    </x-slot>
</x-form-section>
