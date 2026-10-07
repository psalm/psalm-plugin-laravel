<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@php try { throw new \RuntimeException('x'); } catch (\RuntimeException $component) {} @endphp
<x-alert />
{{ $component->getMessage() }}
{{ $component->id() }}
