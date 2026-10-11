<?php /** @var \Illuminate\Support\Collection<int, string>|\ArrayIterator<int, string> $iterated */ ?>
<?php /** @var \Illuminate\Support\Collection<int, string>|list<string> $items */ ?>
@forelse($iterated as $entry)
    {{ $entry }}
@empty
    none
@endforelse
@foreach($items as $item)
    {{ $item }}
@endforeach
{{ $__currentLoopData->count() }}
<?php /** @var \stdClass|int $receiver */ ?>
<?php /** @var \ArrayObject<int, string>|\stdClass $bag */ ?>
@php
$__currentLoopData = 'count';
$receiver->$__currentLoopData();
$__currentLoopData = $bag;
echo e($__currentLoopData[0]);
@endphp
