@interact('c', $row)
    {{ $component }}
    <x-alert>Inner</x-alert>
@endinteract
@php $later = function () { echo $attributes; }; $later(); @endphp
