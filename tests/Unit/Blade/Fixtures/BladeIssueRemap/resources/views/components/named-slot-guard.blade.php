<?php
/**
 * @var \Illuminate\View\ComponentSlot $slot
 * @var \Illuminate\View\ComponentSlot $footer Optional footer.
 * @var \Illuminate\View\ComponentSlot $header
 * @var string $title
 */
?>
<div>{{ $slot }}</div>
@if (isset($footer) && $footer->hasActualContent())
    <footer>{{ $footer }}</footer>
@endif
{{ $header ?? '' }}
@php $header ??= new \Illuminate\View\ComponentSlot(); @endphp
@if (isset($slot)) {{ $slot }} @endif
@if (isset($title)) {{ $title }} @endif
