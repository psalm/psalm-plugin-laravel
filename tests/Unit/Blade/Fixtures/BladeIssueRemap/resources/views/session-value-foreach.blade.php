@foreach (['a' => 1, 'b' => 2] as $key => $value)
    @session('status') {{ $value }} @endsession
    {{ $value }}
@endforeach
