<?php
/**
 * @var \Illuminate\View\InvokableComponentVariable<array{int, string}> $columns
 * @var \Illuminate\View\InvokableComponentVariable<int> $label
 * @var \Illuminate\View\InvokableComponentVariable<\ViewContractFixture\ComponentInvokable> $fresh
 * @var \Illuminate\View\InvokableComponentVariable<string> $untyped
 * @var \Illuminate\View\InvokableComponentVariable $plain
 * @var \Illuminate\View\InvokableComponentVariable<\ViewContractFixture\ComponentInvokable> $me
 * @var string $untypedAsString
 * @var \Closure(): int $untypedAsClosure
 * @var \Illuminate\View\InvokableComponentVariable<null> $clear
 * @var \Illuminate\View\InvokableComponentVariable<list<string>> $items
 */
$resolved = $columns();
/** @psalm-trace $resolved */
?>
{{ $fresh()->label() }} {{ $plain }} {{ $untyped }} {{ $label }}
