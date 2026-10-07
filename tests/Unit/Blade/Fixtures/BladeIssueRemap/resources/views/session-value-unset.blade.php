@php $value = 1; unset($value); @endphp
@session('status') {{ $value }} @endsession
