<?php /** @var array{title: int} $data */ ?>
@includeWhen(true, 'inc.card', $data)
@includeUnless(false, 'inc.card', $data)
@includeFirst(['inc.card'], $data)
