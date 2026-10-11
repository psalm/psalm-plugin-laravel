<?php /** @var string $heading */ ?>
@includeIf('inc.card', ['title' => $heading])
@includeWhen(true, 'inc.card', ['title' => $heading])
@includeFirst(['inc.card'], ['title' => $heading])
@includeIsolated('inc.card', ['title' => $heading])
@each('inc.card', [1, 2], 'count')
