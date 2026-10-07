<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@php $f = function (): int { /** @var \BladeIssueRemapFixture\Gadget $component */ return 1; }; @endphp
<x-alert />
{{ $component->id() }}
