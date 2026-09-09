@props(['submit'])

<div {{ $attributes->merge(['class' => 'md:grid md:grid-cols-3 md:gap-6']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="md:col-span-2">
        <form wire:submit="{{ $submit }}" class="panel">
            <div class="panel-body">
                <div class="grid grid-cols-6 gap-6">
                    {{ $form }}
                </div>
            </div>

            @if (isset($actions))
                <div class="sheet-foot">
                    {{ $actions }}
                </div>
            @endif
        </form>
    </div>
</div>
