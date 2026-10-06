@foreach ((new \Fx\Source)->items() as $outer)
    @foreach ((new \Fx\Source)->items() as $inner)
        {{ $outer }}{{ $inner }}
        @if($loop->parent)
            a
        @endif
        @if($loop->parent !== null)
            b
        @endif
        {{ $loop->parent?->iteration }}
        {{ $loop->parent->iteration ?? 0 }}
        @isset($loop->parent)
            c
        @endisset
    @endforeach
@endforeach
