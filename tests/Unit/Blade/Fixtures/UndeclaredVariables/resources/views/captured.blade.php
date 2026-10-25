<?php /** @var string $title */ ?>
@php
    $upper = static fn () => (function () use ($captured) { return $captured; })();
@endphp
<p>{{ $title }} {{ $siteName }} {{ $app->version() }}</p>
