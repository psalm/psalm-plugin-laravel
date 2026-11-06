<?php /** @var int $heading */ ?>
<h1>{{ $heading }}</h1>
@scopedInclude('inc.card', ['title' => $heading])
