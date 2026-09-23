@foreach((new \Fx\Source)->items() as $item)
    @if($loop->first)
        first
    @endif
    {{ $loop->iteration }}
    @if($loop->even)
        even
    @endif
    {{ $item }}
@endforeach
