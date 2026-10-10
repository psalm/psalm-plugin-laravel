<?php /** @var \Illuminate\Support\ViewErrorBag $errors */ ?>
<?php /** @var string $page */ ?>
<?php /* @var int $plain */ ?>
<?php // @var int $lineComment?>
@php($page ??= 'home')
<p>{{ $errors->first() }}{{ $page }}{{ $plain }}{{ $lineComment }}</p>
