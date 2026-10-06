<x-alert><x-alert>Inner</x-alert></x-alert>

@php
/** @var string $range */
$range = 'x';
@endphp
@if(isset($range))
    {{ $range }}
@endif
