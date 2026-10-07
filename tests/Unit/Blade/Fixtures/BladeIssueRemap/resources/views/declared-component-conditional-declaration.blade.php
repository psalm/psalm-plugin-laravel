<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
<?php if (random_int(0, 1)) { /** @var \BladeIssueRemapFixture\Gadget $component */ $unused = 1;
} ?>
<x-alert />
{{ $component->id() }}
