@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'field-error']) }}>
        <span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}
    </p>
@enderror
