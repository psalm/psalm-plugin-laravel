<?php /** @var string $heading */ ?>
@includeWhen(true, 'inc.card', ['title' => $heading])
@includeUnless(false, 'inc.card', ['title' => $heading])
@includeFirst(['inc.card'], ['title' => $heading])
