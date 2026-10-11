<?php
/**
 * @var string $heading
 * @var int|null $count = null
 */
?>
<h1>{{ $heading }}</h1>
@include('inc.card', ['title' => $heading])
