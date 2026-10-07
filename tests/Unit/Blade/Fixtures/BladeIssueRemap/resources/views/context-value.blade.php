@php $value = 'x'; @endphp
@context('k') {{ $value }} @endcontext
{{ strlen($value) }}
