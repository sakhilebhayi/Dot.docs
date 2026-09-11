@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'field-error']) }}>{{ $message }}</p>
@enderror
