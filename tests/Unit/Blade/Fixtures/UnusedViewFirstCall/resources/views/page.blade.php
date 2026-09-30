@extends('layout')

@section('content')
    {{ $items->first(fn($i) => $i) }}
@endsection
