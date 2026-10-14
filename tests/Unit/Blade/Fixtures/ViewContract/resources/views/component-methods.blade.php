<?php
/**
 * @var \Closure(\ViewContractFixture\Thing): string $status
 * @var \Closure(string, int=): string $pad
 * @var \Closure(string...): string $join
 * @var \Closure(string): \ViewContractFixture\ComponentMethods $withTone
 * @var \Closure(int): string $label
 * @var \Closure(int): int $echo
 */
?>
<p>{{ $status(new \ViewContractFixture\Thing()) }} {{ $pad('a') }} {{ $join('a', 'b') }} {{ $label(1) }} {{ $echo(1) }}</p>
{{ $withTone('x')::class }}
