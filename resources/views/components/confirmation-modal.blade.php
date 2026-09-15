@props(['id' => null, 'maxWidth' => null])

@php
    $modalId = $id ?? md5($attributes->wire('model'));
@endphp

<x-modal :id="$modalId" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="sheet-head">
        <h2 class="h-panel" id="{{ $modalId }}-title">{{ $title }}</h2>
    </div>

    <div class="sheet-body">
        {{ $content }}
    </div>

    <div class="sheet-foot">
        {{ $footer }}
    </div>
</x-modal>
