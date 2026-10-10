<?php /** @var \Illuminate\View\ComponentSlot|null $action */ ?>
@php $hasAction = $action !== null; @endphp
<x-alert>Hi</x-alert>
@if ($hasAction)
    {{ $action->attributes }}
@endif
