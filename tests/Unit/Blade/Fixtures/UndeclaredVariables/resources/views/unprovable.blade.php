<?php /** @var string $title */ ?>
@php extract(array_filter([$title])); @endphp
<p>{{ $title }} {{ $dynamic }}</p>
