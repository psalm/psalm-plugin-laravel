@foreach ((new \Fx\Source)->items() as $outer)
    @foreach ((new \Fx\Source)->items() as $inner)
        {{ $outer }}{{ $inner }}{{ $loop->parent->iteration }}
        @include('partials.row')
    @endforeach
@endforeach
