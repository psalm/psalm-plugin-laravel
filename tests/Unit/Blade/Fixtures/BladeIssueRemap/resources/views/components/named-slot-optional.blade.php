<?php /** @var \Illuminate\View\ComponentSlot|null $footer = null */ ?>
<div>{{ $slot }}</div>
@if (isset($footer) && $footer->hasActualContent())
    <footer>{{ $footer }}</footer>
@endif
{{ $footer ?? '' }}
