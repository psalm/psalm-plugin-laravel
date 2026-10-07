@php $value = 5; @endphp
@if (isset($value))
    ok
@endif
@session('status') {{ $value }} @endsession
