--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * With `findArrKeyNotFound` on, Arr::get() / Arr::pull() report a literal key that cannot exist in
 * the sealed shape they are given, whether or not a default is passed.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2345
 */

// --- positive ---

/** @param array{a: int} $shape */
function test_get_absent_key(array $shape): void
{
    $_result = Arr::get($shape, 'z');
    /** @psalm-check-type-exact $_result = null */
}

/** @param array{a: int} $shape */
function test_get_absent_key_with_default(array $shape): void
{
    $_result = Arr::get($shape, 'z', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback' */
}

/** @param array{a: int} $shape */
function test_pull_absent_key(array $shape): void
{
    $_result = Arr::pull($shape, 'z');
    /** @psalm-check-type-exact $_result = null */
}

/** @param array{a: int} $shape */
function test_pull_absent_key_with_default(array $shape): void
{
    $_result = Arr::pull($shape, 'z', 0);
    /** @psalm-check-type-exact $_result = 0 */
}

/** @param array{a: array{b: int}} $shape */
function test_get_absent_dot_segment(array $shape): void
{
    $_result = Arr::get($shape, 'a.c', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback' */
}

/** @param array{a: array{b: int}} $shape */
function test_get_absent_first_dot_segment(array $shape): void
{
    $_result = Arr::get($shape, 'x.y');
    /** @psalm-check-type-exact $_result = null */
}

/** @param array{a?: array{b: int}} $shape */
function test_get_absent_segment_behind_optional_segment(array $shape): void
{
    $_result = Arr::get($shape, 'a.c');
    /** @psalm-check-type-exact $_result = null */
}

/** The Closure default only defers the return type; the key is absent either way. */
/** @param array{a: int} $shape */
function test_get_absent_key_with_closure_default(array $shape): void
{
    $_result = Arr::get($shape, 'z', static fn (): string => 'fallback');
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array{a: int} $shape */
function test_suppressed_with_psalm_suppress(array $shape): void
{
    /** @psalm-suppress ArrKeyNotFound */
    $_result = Arr::get($shape, 'z');
    /** @psalm-check-type-exact $_result = null */
}

/** A second pull of the same key sees the shape the first one left behind. */
/** @param array{name: string, age: int} $data */
function test_second_pull_of_the_same_key(array $data): void
{
    $_first = Arr::pull($data, 'name');
    /** @psalm-check-type-exact $_first = string */
    $_second = Arr::pull($data, 'name');
    /** @psalm-check-type-exact $_second = null */
}

// --- negative: the key can exist ---

/** @param array{a: int, b: string} $shape */
function test_get_known_key(array $shape): void
{
    $_result = Arr::get($shape, 'b');
    /** @psalm-check-type-exact $_result = string */
}

/** @param array{a?: int} $shape */
function test_get_optional_key(array $shape): void
{
    $_result = Arr::get($shape, 'a', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback'|int */
}

/** @param array{a: array{b: int}} $shape */
function test_get_known_dot_path(array $shape): void
{
    $_result = Arr::get($shape, 'a.b');
    /** @psalm-check-type-exact $_result = int */
}

/** @param array{'a.b': int} $shape */
function test_get_literal_dot_key(array $shape): void
{
    $_result = Arr::get($shape, 'a.b');
    /** @psalm-check-type-exact $_result = int */
}

/** @param array{name: string, age: int} $data */
function test_pull_known_key(array $data): void
{
    $_result = Arr::pull($data, 'name');
    /** @psalm-check-type-exact $_result = string */
}

// --- negative: the shape is not provably closed, or the key is not a literal ---

/** @param array<string, int> $map */
function test_get_generic_array(array $map): void
{
    $_result = Arr::get($map, 'z');
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array{a: int, ...<string, bool>} $shape */
function test_get_unsealed_shape(array $shape): void
{
    $_result = Arr::get($shape, 'z');
    /** @psalm-check-type-exact $_result = mixed */
}

/** Psalm merges a union of shapes into one shape whose differing keys are optional. */
/** @param array{a: int}|array{z: string} $shape */
function test_get_union_of_shapes_merged_with_optional_keys(array $shape): void
{
    $_result = Arr::get($shape, 'z');
    /** @psalm-check-type-exact $_result = null|string */
}

/** @param array{a: int} $shape */
function test_get_dynamic_key(array $shape, string $key): void
{
    $_result = Arr::get($shape, $key);
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array{a: int} $shape */
function test_get_non_shape_intermediate(array $shape): void
{
    $_result = Arr::get($shape, 'a.b');
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array{'a.b'?: int, a: array{b: string}} $shape */
function test_get_optional_literal_dot_key(array $shape): void
{
    $_result = Arr::get($shape, 'a.b');
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array{a: int} $shape */
function test_get_named_arguments_out_of_order(array $shape): void
{
    $_result = Arr::get(key: 'z', array: $shape);
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
ArrKeyNotFound on line %d: Key 'z' does not exist in array{a: int}, so Arr::get() always returns its default
ArrKeyNotFound on line %d: Key 'z' does not exist in array{a: int}, so Arr::get() always returns its default
ArrKeyNotFound on line %d: Key 'z' does not exist in array{a: int}, so Arr::pull() always returns its default
ArrKeyNotFound on line %d: Key 'z' does not exist in array{a: int}, so Arr::pull() always returns its default
ArrKeyNotFound on line %d: Key 'a.c' does not exist in array{a: array{b: int}}, so Arr::get() always returns its default
ArrKeyNotFound on line %d: Key 'x.y' does not exist in array{a: array{b: int}}, so Arr::get() always returns its default
ArrKeyNotFound on line %d: Key 'a.c' does not exist in array{a?: array{b: int}}, so Arr::get() always returns its default
ArrKeyNotFound on line %d: Key 'z' does not exist in array{a: int}, so Arr::get() always returns its default
ArrKeyNotFound on line %d: Key 'name' does not exist in array{age: int}, so Arr::pull() always returns its default
