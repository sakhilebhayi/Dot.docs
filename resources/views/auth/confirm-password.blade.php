<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-6 text-center">
            <h1 class="text-2xl font-display font-semibold text-[var(--ink)]">Confirm password</h1>
            <p class="text-sm text-[var(--ink-soft)] mt-1">This area requires your password to continue</p>
        </div>

        <div class="mb-4 text-sm text-[var(--ink-soft)]">
            {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
        </div>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf

            <div>
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" error="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" autofocus />
                <x-input-error for="password" field="password" :announce="false" />
            </div>

            <div class="flex justify-end mt-4">
                <x-button class="ms-4">
                    {{ __('Confirm') }}
                </x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
