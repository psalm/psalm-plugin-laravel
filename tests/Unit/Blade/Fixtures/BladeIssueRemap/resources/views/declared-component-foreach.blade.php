<?php /** @var \BladeIssueRemapFixture\Widget $component */ ?>
@foreach ([new \BladeIssueRemapFixture\Widget()] as $component) @endforeach
<x-alert />
{{ $component->id() }}
