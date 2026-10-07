<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@php $ref = &$component; $ref = new \BladeIssueRemapFixture\Gadget(); @endphp
<x-alert />
{{ $component->gadget() }}
{{ $component->id() }}
