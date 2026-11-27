{{-- R-54: the claim page, in the palette Dot.docs already uses for its own sign-in pages (the guest layout: paper, ink,
     gold, blue). Shown when an account here already uses the email address of the InfoDot sign-in. Nothing on the account
     is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-6 text-center">
            <h1 class="text-2xl font-display font-semibold text-[var(--ink)]">Confirm it is you</h1>
            <p class="text-sm text-[var(--ink-soft)] mt-2" style="overflow-wrap:anywhere">An account here already uses <strong class="text-[var(--ink)]">{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your password, your documents and your API tokens stay as they are.</p>
        </div>

        @if (session('dotsso_status'))
            <div class="mb-4 font-medium text-sm text-[var(--accent)]" role="status">{{ session('dotsso_status') }}</div>
        @endif

        <form method="POST" action="{{ $verifyUrl }}" novalidate>
            @csrf
            <div>
                <x-label for="code" value="Code from the email" />
                <x-input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                         maxlength="{{ $codeLength }}" required autofocus
                         class="block mt-1 w-full dsso-code" aria-describedby="code-help code-error"
                         :aria-invalid="$errors->has('code') ? 'true' : 'false'" />
                <p id="code-help" class="mt-1.5 text-xs text-[var(--ink-soft)]">It can take up to a minute to arrive. It works for {{ $minutes }} minutes, and you have {{ $maxAttempts }} tries.</p>
                <p id="code-error" class="mt-1.5 text-sm text-red-700 dsso-err" role="alert">{{ $errors->first('code') }}</p>
            </div>

            <x-button type="submit" class="w-full justify-center mt-4 min-h-[44px]">Confirm and continue</x-button>
        </form>

        <div class="mt-5 flex items-center justify-between gap-3">
            <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="inline-flex items-center min-h-[44px] text-sm font-medium text-[var(--blue-deep)] hover:text-[var(--blue)] transition-colors">Send a new code</button></form>
            <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="inline-flex items-center min-h-[44px] text-sm font-medium text-[var(--blue-deep)] hover:text-[var(--blue)] transition-colors">Cancel</button></form>
        </div>
    </x-authentication-card>
</x-guest-layout>
