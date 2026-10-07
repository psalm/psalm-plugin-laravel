<?php /** @var string $cl */ ?>
@if(\random_int(0, 1)) {{ $cl ?? '' }} @endif
<?php $fn2 = function () use ($cl) {
    return $cl;
}; ?>
{{ $fn2() }}
