@props(['type' => 'info'])
<x-alert>nested</x-alert>
@php
/** @var ?\Illuminate\View\ComponentSlot $maybe */
$maybe = null;
@endphp
{{ $maybe->toHtml() }}
