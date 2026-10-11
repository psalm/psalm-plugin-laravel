<?php /** @var list<int> $counts */ ?>
@foreach ($counts as $count)
    @include('inc.card', ['title' => 'Row'])
@endforeach
@php $count = 3; @endphp
@include('inc.card', ['title' => 'Total'])
