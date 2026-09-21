@props(['type' => 'info'])
{{ $attributes->merge(['class' => 'alert']) }}
{{ $slot }}
