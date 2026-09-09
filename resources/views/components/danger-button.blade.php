{{-- Destructive actions read as danger through the lamp and the word, never
     through the fill alone: the button carries a danger nib beside its label. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn btn-danger']) }}>
    <span class="lamp lamp-danger" aria-hidden="true"></span>
    {{ $slot }}
</button>
