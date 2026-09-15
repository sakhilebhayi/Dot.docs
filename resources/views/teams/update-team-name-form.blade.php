<x-form-section submit="updateTeamName">
    <x-slot name="title">
        {{ __('Team Name') }}
    </x-slot>

    <x-slot name="description">
        {{ __('The team\'s name and owner information.') }}
    </x-slot>

    <x-slot name="form">
        <div class="field-row">
            <x-label value="{{ __('Team Owner') }}" />

            <div class="toolbar">
                <span class="face-plate">
                    <img src="{{ $team->owner->profile_photo_url }}" alt="{{ $team->owner->name }}">
                </span>
                <span class="list-key">
                    {{ $team->owner->name }}
                    <span class="list-sub">{{ $team->owner->email }}</span>
                </span>
            </div>
        </div>

        <div class="field-row">
            <x-label for="name" value="{{ __('Team Name') }}" />

            <x-input id="name"
                        type="text"
                        wire:model="state.name"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="name" />
        </div>
    </x-slot>

    @if (Gate::check('update', $team))
        <x-slot name="actions">
            <x-action-message on="saved">
                {{ __('Saved.') }}
            </x-action-message>

            <x-button>
                {{ __('Save') }}
            </x-button>
        </x-slot>
    @endif
</x-form-section>
