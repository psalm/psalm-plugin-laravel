<?php /** @var string $title */ ?>
@php if ($title !== '') { $count = 1; } @endphp
@include('inc.card', ['title' => $title])
