@props([])
@php $attributes = $flag ? null : $attributes; @endphp
<x-alert />
{{ $attributes->merge([]) }}
