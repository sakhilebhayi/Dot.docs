<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-6 text-center">
            <h1 class="text-2xl font-display font-semibold text-[var(--ink)]">Welcome back</h1>
            <p class="text-sm text-[var(--ink-soft)] mt-1">Sign in to your Dot.docs account</p>
        </div>

        <x-validation-errors class="mb-4" />

        @session('status')
            <div class="mb-4 font-medium text-sm text-[var(--accent)]">
                {{ $value }}
            </div>
        @endsession

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div>
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" error="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
                <x-input-error for="email" field="email" :announce="false" />
            </div>

            <div class="mt-4">
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" error="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
                <x-input-error for="password" field="password" :announce="false" />
            </div>

            <div class="block mt-4">
                <label for="remember_me" class="flex items-center min-h-[44px]">
                    <x-checkbox id="remember_me" name="remember" />
                    <span class="ms-2 text-sm text-[var(--ink-soft)]">{{ __('Remember me') }}</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                @if (Route::has('password.request'))
                    <a class="inline-flex items-center min-h-[44px] text-sm font-medium text-[var(--blue-deep)] hover:text-[var(--blue)] transition-colors" href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif

                <x-button class="ms-4">
                    {{ __('Log in') }}
                </x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
