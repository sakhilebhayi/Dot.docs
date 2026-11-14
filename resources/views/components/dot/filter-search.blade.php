{{--
  <x-dot.filter-search>: the compact in-page filter (InfoDot's 36px .ix-input), NOT the pill.
  Presentation only. Props: name (default "q"), placeholder, value, label (aria-label, default "Filter"), id.
  The id is "dsr-filter-<name>" unless given, the same on every render (snapshots, Livewire morphs); pass an id when
  two filters with the same name share a page. The input also carries data-dsr-filter, a stable hook for tests and scripts:
  `class` is printed before `id`, so a selector such as `<input id=` in a test does not match; use [data-dsr-filter]. There is no <form> here: other attributes (wire:model, x-model, form...)
  are merged onto the <input>.
--}}
@props([
    'name' => 'q',
    'placeholder' => 'Filter',
    'value' => '',
    'label' => 'Filter',
    'id' => null,
])
<input {{ $attributes->merge([
    'id' => $id ?? 'dsr-filter-'.(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-') ?: 'q'),
    'type' => 'search',
    'name' => $name,
    'value' => $value,
    'placeholder' => $placeholder,
    'aria-label' => $label,
    'autocomplete' => 'off',
    'data-dsr-filter' => '',
])->class(['dsr-filter']) }} />
