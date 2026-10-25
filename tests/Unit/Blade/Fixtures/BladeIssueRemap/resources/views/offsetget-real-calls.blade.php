<?php /** @var \BladeIssueRemapFixture\Catalog|array<string, string> $obj */ ?>
@php $v = rand() ? $unknown : new \BladeIssueRemapFixture\Catalog(); @endphp
{{ $obj->items()['k'] }}
{{ $v->items()['k'] }}
{{ $cls::make()['k'] }}
