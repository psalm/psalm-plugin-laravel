<?php /** @var string $heading */ ?>
<h1>{{ $heading }}</h1>
@include('inc.card', ['title' => $heading])
{{-- @psalm-suppress InvalidViewVariableType --}}
@include('inc.card', ['title' => $heading])
