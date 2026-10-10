@php($item = \BladeIssueRemapFixture\MaybeItem::find())
@php($hasItem = $item !== null && $item->ready())
<x-alert>Hi</x-alert>
@if ($hasItem)
  <p>{{ $item->name }}</p>
@endif
<?php
/**
 * @var \BladeIssueRemapFixture\MaybeItem|null $action
 */
?>
<?php
$hasAction = isset($action) && $action->ready();
?>
<x-alert>Hi</x-alert>
@if ($hasAction)
  <p>{{ $action->name }}</p>
@endif
