@foreach ((new \Fx\Source)->items() as $outer)
    @foreach ((new \Fx\Source)->items() as $middle)
        @foreach ((new \Fx\Source)->items() as $inner)
            {{ $outer }}{{ $middle }}{{ $inner }}
            {{ $loop->parent->parent->iteration }}.{{ $loop->parent->iteration }}.{{ $loop->iteration }}
        @endforeach
        {{ $loop->parent->iteration }}
        @if($loop->parent->first)
            first
        @endif
    @endforeach
@endforeach
