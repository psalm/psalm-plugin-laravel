@foreach ((new \Fx\Source)->items() as $outer)
    @forelse ((new \Fx\Source)->items() as $inner)
        {{ $inner }}{{ $loop->parent->iteration }}
    @empty
        {{ $outer }}{{ $loop->parent->iteration }}
    @endforelse
@endforeach
