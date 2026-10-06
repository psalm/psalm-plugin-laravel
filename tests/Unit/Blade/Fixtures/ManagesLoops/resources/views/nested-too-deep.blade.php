@foreach ((new \Fx\Source)->items() as $outer)
    @foreach ((new \Fx\Source)->items() as $inner)
        {{ $outer }}{{ $inner }}{{ $loop->parent->parent->iteration }}
    @endforeach
@endforeach
