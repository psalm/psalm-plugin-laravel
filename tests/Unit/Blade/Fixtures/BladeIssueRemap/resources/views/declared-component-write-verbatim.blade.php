<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@verbatim<?php $component = new \BladeIssueRemapFixture\Gadget(); ?>@endverbatim
<x-alert />
{{ $component->gadget() }}
{{ $component->id() }}
