@php $defaults = ['size' => 3]; @endphp
@props($defaults)
@props(['size' => 'lg'])
@php /** @psalm-trace $size */ $probe = [$size]; @endphp
