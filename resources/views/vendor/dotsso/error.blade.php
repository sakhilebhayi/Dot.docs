{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div role="alert">
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-display font-semibold text-[var(--ink)]">{{ $headline }}</h1>
                <p class="text-sm text-[var(--ink-soft)] mt-2">{{ $body }}</p>
            </div>
            <div class="flex flex-col gap-2">
                @if ($retry)<a class="btn btn-primary justify-center min-h-[44px]" href="{{ $retryUrl }}">Start again</a>@endif
                <a class="inline-flex items-center justify-center min-h-[44px] text-sm font-medium text-[var(--blue-deep)] hover:text-[var(--blue)] transition-colors" href="{{ $homeUrl }}">Back to Dot.docs</a>
            </div>
            <p class="mt-4 text-xs text-[var(--ink-soft)] text-center">Reference: {{ $code }}</p>
        </div>
    </x-authentication-card>
</x-guest-layout>
