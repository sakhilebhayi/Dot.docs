{{--
  The message for one validation key. With `field` (the input's id) it gets the id that field's aria-describedby names
  and is announced when it appears (R2-6 / A-03); see <x-input error="...">.
--}}
@props(['for', 'field' => null])

@error($for)
    <p {{ $attributes->merge(array_filter(['class' => 'field-error', 'id' => $field ? $field.'-error' : null, 'role' => $field ? 'alert' : null])) }}>{{ $message }}</p>
@enderror
