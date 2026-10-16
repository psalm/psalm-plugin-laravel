<?php /** @var \Illuminate\View\ComponentSlot|null $footer = null */ ?>
<div>{{ $slot }}</div>
@if (isset($footer) && $footer->hasActualContent())
    <footer>{{ $footer }}</footer>
@endif
{{ $footer ?? '' }}
@if (isset($footer))
    @if (isset($footer)) {{ $footer }} @endif
    {{ $footer ?? '' }}
    @php $footer ??= new \Illuminate\View\ComponentSlot(); @endphp
@endif
