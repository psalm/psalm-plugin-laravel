@forelse ((new \Fx\Source)->items() as $outer)
    @forelse ((new \Fx\Source)->items() as $middle)
        @forelse ((new \Fx\Source)->items() as $inner)
            {{ $outer }}{{ $middle }}{{ $inner }}
            {{ $loop->parent->parent->iteration }}.{{ $loop->parent->iteration }}
        @empty
            {{ $loop->parent->iteration }}
        @endforelse
    @empty
        none
    @endforelse
@empty
    none
@endforelse
