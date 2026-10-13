<?php /** @var int $heading */ ?>
<h1>{{ $heading }}</h1>
@include('inc.card', ['title' => $heading, 'count' => 1])
@include('inc.card', ['title' => 'Fine', 'count' => 2, 'note' => $undeclared])
@includeIf('inc.card', ['title' => $heading])
