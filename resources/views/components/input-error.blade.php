{{--
  The message for one validation key. With `field` (the input's id) it gets the id that field's aria-describedby names
  (R2-6 / A-03; see <x-input error="...">) and is rendered on EVERY render, empty while the key has no error: an alert
  that arrives together with its words is not reliably announced, one whose words change is (R2-12). An empty
  .field-error takes no room. On a page that also shows <x-validation-errors> (the sign-in pages), pass
  :announce="false": the summary is the one alert and takes the focus, and the field still reads its own message
  through aria-describedby (R2-12 / A-05).
--}}
@props(['for', 'field' => null, 'announce' => true])

@if ($field)
    <p {{ $attributes->merge(array_filter(['class' => 'field-error', 'id' => $field.'-error', 'role' => $announce ? 'alert' : null])) }}>@error($for){{ $message }}@enderror</p>
@else
    @error($for)
        <p {{ $attributes->merge(['class' => 'field-error']) }}>{{ $message }}</p>
    @enderror
@endif
