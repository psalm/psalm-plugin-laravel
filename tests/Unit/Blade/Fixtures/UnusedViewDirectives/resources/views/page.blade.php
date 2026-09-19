@extends('layout')

@section('content')
    @each('row', $rows, 'row')
    @component('card')x@endcomponent
    @includeWhen(true, 'whenp')
@endsection
