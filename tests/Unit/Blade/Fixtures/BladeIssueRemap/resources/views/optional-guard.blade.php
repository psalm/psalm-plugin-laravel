<?php
/**
 * @var string $label The caption
 * @var bool $stacked Optional, defaults to true
 * @var \BladeIssueRemapFixture\Greeter $greeter
 * @var string $title
 */
$label ??= '';
$stacked ??= true;
?>
<div>{{ $label }} {{ $greeter ?? 'none' }}</div>
@if(isset($title)) {{ $title }} @endif
