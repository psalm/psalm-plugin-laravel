<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@php \BladeIssueRemapFixture\Gadget:: empty($component); @endphp
<x-alert />
{{ $component->id() }}
