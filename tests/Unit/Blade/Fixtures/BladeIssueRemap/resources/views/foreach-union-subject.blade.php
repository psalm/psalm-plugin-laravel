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
