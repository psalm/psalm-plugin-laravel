<?php /** @var string $cl */ ?>
@if(\random_int(0, 1)) {{ $cl ?? '' }} @endif
<?php $fn2 = fn()
    => $cl; ?>
{{ $fn2() }}
