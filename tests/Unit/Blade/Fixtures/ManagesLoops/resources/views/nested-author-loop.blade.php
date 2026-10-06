@foreach ((new \Fx\Source)->items() as $outer)
    @foreach ((new \Fx\Source)->items() as $inner)
        @php $loop = (object) ['parent' => null]; @endphp
        {{ $outer }}{{ $inner }}{{ $loop->parent->iteration }}
    @endforeach
@endforeach
