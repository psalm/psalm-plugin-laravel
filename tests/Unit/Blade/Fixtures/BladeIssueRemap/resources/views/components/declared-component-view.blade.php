@props(['title' => 'x'])
<?php
/**
 * @var \BladeIssueRemapFixture\Widget $component
 */
?>
<div {{ $attributes }}>
<x-alert />
<form id="{{ $component->id() }}"></form>
{{ $attributes->get('a') }}
</div>
