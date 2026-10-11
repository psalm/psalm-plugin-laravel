@foreach((new \Fx\Source)->items() as $outer)
    @if ($loop->parent)
        never at depth 1
    @endif
    @foreach((new \Fx\Source)->items() as $inner)
        @if ($loop->parent)
            {{ $outer }}{{ $inner }}{{ $loop->parent->iteration }}
            @php /** @psalm-trace $parentIteration */ $parentIteration = $loop->parent->iteration; @endphp
        @endif
    @endforeach
@endforeach
