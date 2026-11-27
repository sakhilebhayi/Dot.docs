<x-guest-layout>
    <div class="pt-4 bg-[var(--paper)]">
        <div class="min-h-screen flex flex-col items-center pt-10 sm:pt-16 px-5 pb-16">
            <div>
                <x-authentication-card-logo />
            </div>

            <div class="w-full sm:max-w-2xl mt-8 p-6 sm:p-10 bg-[var(--surface-raised)] border border-[var(--line)] overflow-hidden sm:rounded-[var(--r-canvas)] prose prose-neutral max-w-none prose-headings:font-display prose-headings:text-[var(--ink)] prose-p:text-[var(--ink-soft)] prose-li:text-[var(--ink-soft)] prose-a:text-[var(--blue-deep)] hover:prose-a:text-[var(--blue)]">
                {!! $cookies !!}
            </div>
        </div>
    </div>
</x-guest-layout>
