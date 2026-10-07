<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@php $f = function (\BladeIssueRemapFixture\Gadget $component): string { /** @var \BladeIssueRemapFixture\Gadget $component */ return $component->gadget(); }; @endphp
<x-alert />
{{ $component->id() }}
