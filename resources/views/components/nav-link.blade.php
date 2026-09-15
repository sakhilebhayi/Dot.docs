@props(['active' => false])

<a {{ $attributes->merge(['class' => 'rail-item '.(($active ?? false) ? 'is-current' : '')]) }}>
    {{ $slot }}
</a>
