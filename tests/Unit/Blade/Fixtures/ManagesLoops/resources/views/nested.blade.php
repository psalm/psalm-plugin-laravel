@foreach ((new \Fx\Source)->items() as $outer)
    @foreach ((new \Fx\Source)->items() as $inner)
        {{ $outer }}{{ $inner }}
        {{ $loop->parent->iteration }}.{{ $loop->iteration }}
        @if($loop->parent->first)
            first
        @endif
    @endforeach
@endforeach
