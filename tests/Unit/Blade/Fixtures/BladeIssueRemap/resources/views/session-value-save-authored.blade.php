@php $value = 5; @endphp
@php if (isset($value)) { $__sessionPrevious[] = $value; } @endphp
@session('status') {{ $value }} @endsession
