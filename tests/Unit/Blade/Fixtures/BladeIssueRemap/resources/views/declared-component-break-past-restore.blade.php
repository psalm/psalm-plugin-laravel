<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@for ($i = 0; $i < 1; $i++)
<x-alert>
@break
</x-alert>
@endfor
<x-other />
{{ $component->id() }}
