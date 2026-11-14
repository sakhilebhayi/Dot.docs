<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{-- config('app.name') is stale ("Laravel") in this environment's .env — hardcoded here so the
branded theme doesn't inherit that mismatch. See wiki.md changelog for the known gap. --}}
<img src="{{ asset('images/logo-light.png') }}" class="logo" alt="Dot.docs">
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
&copy; {{ date('Y') }} Dot.docs. {{ __('All rights reserved.') }}<br>
{{ __('Real-time collaborative documents.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
