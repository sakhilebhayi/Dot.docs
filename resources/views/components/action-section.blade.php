{{-- The read-and-act counterpart of <x-form-section>: same ledger panel, no
     form around it. --}}
<section {{ $attributes->merge(['class' => 'panel']) }}>
    <div class="panel-head">
        <x-section-title>
            <x-slot name="title">{{ $title }}</x-slot>
            <x-slot name="description">{{ $description }}</x-slot>
        </x-section-title>
    </div>

    <div class="panel-body">{{ $content }}</div>
</section>
