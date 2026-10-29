@php
/** @param \AnnotateIncludesFixture\ProbeBox<int> $value */
function take_generic(mixed $value): void {}
take_generic($x);
@endphp
@includeIsolated('generic-leaf', ['x' => $x])
