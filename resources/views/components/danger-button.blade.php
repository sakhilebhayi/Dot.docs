{{-- Destructive actions read as danger through the DOT and the word, never
     through the fill alone: the button carries a danger dot beside its label,
     and its label always says what it deletes. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn btn-danger']) }}>
    <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span>
    {{ $slot }}
</button>
