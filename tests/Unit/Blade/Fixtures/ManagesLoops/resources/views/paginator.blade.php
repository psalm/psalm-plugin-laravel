@foreach((new \Fx\Source)->paginator() as $item)
    {{ $loop->iteration }}
@endforeach
