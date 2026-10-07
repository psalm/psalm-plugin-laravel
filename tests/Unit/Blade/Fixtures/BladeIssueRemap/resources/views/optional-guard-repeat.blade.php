<?php /** @var string $label */ ?>
{{ $label ?? '' }}
{{ $label ?? '' }}
@isset($label) {{ $label }} @endisset
@if(isset($label)) {{ $label }} @endif
