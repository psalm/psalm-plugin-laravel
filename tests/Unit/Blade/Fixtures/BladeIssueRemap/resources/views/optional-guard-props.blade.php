@props(['label' => ''])
<?php /** @var string $label */ ?>
@if(\random_int(0, 1)) {{ $label ?? 'x' }} @endif
{{ $label }}
