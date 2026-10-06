@foreach ((new \Fx\Source)->items() as $item)
    {{ $item }}{{ $loop->parent->iteration }}
@endforeach
