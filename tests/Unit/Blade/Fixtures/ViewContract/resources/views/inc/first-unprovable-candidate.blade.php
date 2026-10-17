<?php
/**
 * @var int $heading
 * @var string $other
 */
?>
@includeFirst([$other, 'inc.card'], ['title' => $heading])
@includeFirst(['pkg::inc.card', 'inc.card'], ['title' => $heading])
@includeFirst(['first' => 'inc.card'], ['title' => $heading])
