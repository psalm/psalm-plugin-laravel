<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@foreach ([[1, new \BladeIssueRemapFixture\Gadget()]] as list($k, $component))
<x-alert />
{{ $component->gadget() }}
{{ $component->id() }}
@endforeach
