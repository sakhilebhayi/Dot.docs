{{-- The heading is plain text: layouts/app.blade.php renders the `header`
     slot as this page's one <h1>. --}}
<x-app-layout>
    <x-slot name="header">{{ __('Profile') }}</x-slot>

    <div class="stack">
        @if (Laravel\Fortify\Features::canUpdateProfileInformation())
            @livewire('profile.update-profile-information-form')

            <x-section-border />
        @endif

        @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
            @livewire('profile.update-password-form')

            <x-section-border />
        @endif

        @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
            @livewire('profile.two-factor-authentication-form')

            <x-section-border />
        @endif

        @livewire('profile.logout-other-browser-sessions-form')

        @if (Laravel\Jetstream\Jetstream::hasAccountDeletionFeatures())
            <x-section-border />

            @livewire('profile.delete-user-form')
        @endif
    </div>
</x-app-layout>
