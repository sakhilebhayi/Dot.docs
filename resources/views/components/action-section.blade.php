<div {{ $attributes->merge(['class' => 'md:grid md:grid-cols-3 md:gap-6']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="md:col-span-2">
        <div class="panel">
            <div class="panel-body">{{ $content }}</div>
        </div>
    </div>
</div>
