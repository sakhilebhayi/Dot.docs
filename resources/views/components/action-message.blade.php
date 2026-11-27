@props(['on'])

@php
    $said = $slot->isEmpty() ? 'Saved.' : $slot;
@endphp

{{-- R2-9 (WCAG 4.1.3): the confirmation is announced through a status region that is always in the page; only
     its words come and go. It is visually hidden and taken out of the flow (.sr-only is absolutely positioned),
     so the form's footer lays out exactly as before. The visible word below is the same text, hidden from
     assistive technology so it is not read twice. --}}
<div class="sr-only" role="status"
    x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 2000); })">
    <span x-show="shown" style="display: none;">{{ $said }}</span>
</div>
<div x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 2000); })"
    x-show="shown"
    style="display: none;"
    aria-hidden="true"
    {{ $attributes->merge(['class' => 'micro']) }}>
    {{ $said }}
</div>
