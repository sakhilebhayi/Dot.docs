{{-- A settings form as a ledger panel: head (what it is), body (the fields),
     foot (the action). Jetstream's three-column card grid is gone — panels in
     this product share edges and never float. --}}
@props(['submit'])

<form wire:submit="{{ $submit }}" {{ $attributes->merge(['class' => 'panel']) }}>
    <div class="panel-head">
        <x-section-title>
            <x-slot name="title">{{ $title }}</x-slot>
            <x-slot name="description">{{ $description }}</x-slot>
        </x-section-title>
    </div>

    <div class="panel-body">
        {{ $form }}
    </div>

    @if (isset($actions))
        <div class="panel-foot">
            {{ $actions }}
        </div>
    @endif
</form>
