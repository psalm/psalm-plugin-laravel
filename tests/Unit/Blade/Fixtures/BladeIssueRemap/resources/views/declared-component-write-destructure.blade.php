<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@php $o = []; [$o['x'], $component] = [1, new \BladeIssueRemapFixture\Gadget()]; @endphp
<x-alert />
{{ $component->gadget() }}
{{ $component->id() }}
