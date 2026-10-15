@aware(['color' => 5])
@props(['color' => 'gray', 'size' => 'lg'])
@php /** @psalm-trace $color $size */ $probe = [$color, $size]; @endphp
