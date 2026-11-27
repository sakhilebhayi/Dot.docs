<div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-10 sm:pt-0 px-5 overflow-hidden">
    {{-- R2-9: Fair Copy's plain ground, no photograph fetched from another host and no scrim; the card is a
         raised surface edged by the hairline, without a shadow (the document paper keeps the product's only one). --}}

    <div class="relative z-10">
        {{ $logo }}
    </div>

    <div class="relative z-10 w-full sm:max-w-md mt-8 px-6 sm:px-8 py-8 bg-[var(--surface-raised)] border border-[var(--line)] overflow-hidden sm:rounded-[var(--r-canvas)]">
        {{ $slot }}
    </div>
</div>
