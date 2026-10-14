<?php /** @var int $heading */ ?>
@extends('inc.card', ['title' => $heading])
@section('body')
    <p>{{ $heading }}</p>
@endsection
