@for ($i = 0; $i < 2; $i++)
    @foreach ((new \Fx\Source)->items() as $item)
        {{ $i }}{{ $item }}{{ $loop->parent->iteration }}
    @endforeach
@endfor
