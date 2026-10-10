@props(['size' => 'md'])
@if ($compact)
    @php $size = 'sm'; @endphp
@endif
{{ $size }}
