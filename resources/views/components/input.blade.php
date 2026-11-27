{{--
  A text field. `error` names the validation key this field answers to (R2-6 / A-03): while that key has an error the
  field says so (aria-invalid) and points at the message (aria-describedby="{id}-error"), which <x-input-error
  for="{key}" field="{id}"> renders with that id. Without `error` it is a plain field, as before.
--}}
@props(['disabled' => false, 'error' => null])

@php
    $invalid = $error !== null && $errors->has($error) && $attributes->has('id');
@endphp

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'field'])->merge($invalid ? ['aria-invalid' => 'true', 'aria-describedby' => $attributes->get('id').'-error'] : []) !!}>
