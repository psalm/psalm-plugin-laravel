<?php /** @var \Illuminate\View\ComponentSlot $footer */ ?>
@if (isset($footer)) {{ $footer }} @endif
{{ $footer ?? '' }}
