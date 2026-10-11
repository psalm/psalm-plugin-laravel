@foreach(['a' => 1, 'b' => 2] as $value)
    @session('status') {{ $value }} @endsession
@endforeach
@php $value = null; @endphp
@context('trace') {{ $value }} @endcontext
@php $value = 5; @endphp
@if (isset($value)) set @endif
@session('status') {{ $value }} @endsession
