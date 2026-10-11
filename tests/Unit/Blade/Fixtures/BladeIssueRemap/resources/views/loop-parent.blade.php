{{-- @var list<list<list<int>>> $rows --}}
@foreach($rows as $row)
    @foreach($row as $cell)
        {{ $loop->parent->iteration }}
        @foreach($cell as $number)
            {{ $loop->parent->parent->iteration }}{{ $number }}
        @endforeach
    @endforeach
@endforeach
<?php /** @var object{parent: null|object{iteration: int}} $node */ ?>
{{ $node->parent->iteration }}
