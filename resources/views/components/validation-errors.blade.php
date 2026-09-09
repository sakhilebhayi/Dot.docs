@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'note']) }}>
        <span class="lamp lamp-danger" aria-hidden="true"></span>
        <div>
            <p style="margin:0 0 var(--s2)">{{ __('That did not go through.') }}</p>
            <ul style="margin:0;padding-left:var(--s4)">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
