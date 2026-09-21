@props(['type' => 'info'])
<x-alert>nested</x-alert>
{{ $attributes->merge(['class' => 'alert']) }}
{{ $slot }}
