@php
/** @psalm-assert !null $value */
function ensure_assert(mixed $value): void {
    if ($value === null) {
        throw new \RuntimeException();
    }
}
ensure_assert($x);
@endphp
@includeIsolated('assert-leaf', ['x' => $x])
