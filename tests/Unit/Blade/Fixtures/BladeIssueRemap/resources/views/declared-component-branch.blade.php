@if (random_int(0, 1) === 1)
<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@else
<?php /** @var \BladeIssueRemapFixture\Gadget $component */ ?>
@endif
<x-alert />
{{ $component->id() }}
{{ $component->gadget() }}
