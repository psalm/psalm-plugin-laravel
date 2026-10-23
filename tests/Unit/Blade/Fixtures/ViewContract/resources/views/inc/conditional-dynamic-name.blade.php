<?php
/**
 * @var int $heading
 * @var string $view
 */
?>
@includeWhen(true, $view, ['title' => $heading])
@includeUnless(false, $view, ['title' => $heading])
