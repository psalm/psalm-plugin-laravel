--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 *
 * Regression (round-4 review): Codebase::classImplements() is non-reflexive - it never returns
 * true for `classImplements($fqcn, $fqcn)` itself. So a bare interface-typed argument - e.g. a
 * generic-forwarding `function f(Arrayable $x) { return collect($x); }` - used to miss the
 * UnitEnum check and fall through to a plain-object fallback that overrode the stub's
 * already-correct template binding with Collection<array-key, mixed>.
 *
 * Current design: CollectionInputTypeResolver::resolve() handles this inline for its only
 * interface-sensitive branch (UnitEnum) via `classImplements($fqcn, UnitEnum::class)` plus a
 * case-insensitive FQCN identity fallback (covers a bare `UnitEnum $x` param, where $fqcn IS
 * `UnitEnum` itself). Every non-enum interface type - Arrayable, Enumerable, Traversable,
 * Jsonable, JsonSerializable, and user interfaces extending them - isn't special-cased at all:
 * resolve() returns null and defers to the stub's own template inference, which resolves the
 * interface subtyping against its own Arrayable/iterable-typed params natively.
 */

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

// Case 1: the exact regression repro - must-pass base-parity case.
/**
 * @template T
 * @param Arrayable<int, T> $x
 * @return Collection<int, T>
 */
function collectArrayableParam(Arrayable $x): Collection {
    return collect($x);
}

// Case 2: Enumerable-typed param - binding preserved.
/** @param Enumerable<int, string> $x */
function collectEnumerableParam(Enumerable $x): Collection {
    $result = collect($x);
    /** @psalm-check-type-exact $result = Collection<int, string> */
    return $result;
}

// Case 3: Traversable-typed param - binding preserved.
/** @param \Traversable<int, string> $x */
function collectTraversableParam(\Traversable $x): Collection {
    $result = collect($x);
    /** @psalm-check-type-exact $result = Collection<int, string> */
    return $result;
}

// Case 4: Jsonable-typed param - uniform [array-key, mixed] claim.
function collectJsonableParam(Jsonable $x): Collection {
    $result = collect($x);
    /** @psalm-check-type-exact $result = Collection<array-key, mixed> */
    return $result;
}

// Case 5: JsonSerializable-typed param - uniform [array-key, mixed] claim.
function collectJsonSerializableParam(\JsonSerializable $x): Collection {
    $result = collect($x);
    /** @psalm-check-type-exact $result = Collection<array-key, mixed> */
    return $result;
}

// Case 6: user interface extending Arrayable - not enum-shaped, so resolve() defers; Psalm's
// own argument-type checking resolves MyArrayableDto's Arrayable subtyping against the stub.
/** @extends Arrayable<int, string> */
interface MyArrayableDto extends Arrayable
{
}

function collectUserInterfaceParam(MyArrayableDto $x): Collection {
    $result = collect($x);
    /** @psalm-check-type-exact $result = Collection<int, string> */
    return $result;
}

// Case 7: bare UnitEnum-typed param - matched by resolve()'s case-insensitive identity fallback
// (classImplements($fqcn, UnitEnum::class) can't match $fqcn === UnitEnum::class itself). With
// the 12.14+ Laravel floor (see composer.json), the enum-first wrap is unconditional, so the
// claim is sound regardless of what concrete enum is actually passed at runtime.
function collectUnitEnumParam(\UnitEnum $x): Collection {
    $result = collect($x);
    /** @psalm-check-type-exact $result = Collection<0, UnitEnum> */
    return $result;
}

// Case 8: interface-typed argument through Collection::make() too, not just collect().
/**
 * @template T
 * @param Arrayable<int, T> $x
 * @return Collection<int, T>
 */
function makeArrayableParam(Arrayable $x): Collection {
    return Collection::make($x);
}

?>
--EXPECTF--
