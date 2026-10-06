@session('status')
    <p>{{ $value }}</p>
@endsession

@context('locale')
    <p>{{ $value }}</p>
@endcontext

@php
    if (random_int(0, 1) === 1) {
        $__authorLocal = [1];
    }
    echo count($__authorLocal);
@endphp
