@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="sheet-head">
        <span class="lamp lamp-danger" aria-hidden="true"></span>
        <h2 class="h-panel" style="flex:1 1 auto">{{ $title }}</h2>
    </div>

    <div class="sheet-body">
        {{ $content }}
    </div>

    <div class="sheet-foot">
        {{ $footer }}
    </div>
</x-modal>
