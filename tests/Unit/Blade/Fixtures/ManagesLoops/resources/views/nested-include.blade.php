@foreach ((new \Fx\Source)->items() as $outer)
    @foreach ((new \Fx\Source)->items() as $inner)
        {{ $outer }}{{ $inner }}
        @include('partials.row')
    @endforeach
@endforeach
