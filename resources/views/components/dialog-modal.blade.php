@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="sheet-head">
        <h2 class="h-panel">{{ $title }}</h2>
    </div>

    <div class="sheet-body">
        {{ $content }}
    </div>

    <div class="sheet-foot">
        {{ $footer }}
    </div>
</x-modal>
