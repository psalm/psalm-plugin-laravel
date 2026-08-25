--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Arr::get() should infer the value type from a known, sealed array shape
 * (`TKeyedArray` with `fallback_params === null`), mirroring `Arr::get()`'s
 * actual dot-notation resolution order (whole-key lookup first, then segment
 * walk) instead of falling back to reflection's plain `mixed`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1387
 */

// --- positive: known key on a closed shape ---

/** @param array{a: int, b: string} $shape */
function test_get_known_key(array $shape): void
{
    $_result = Arr::get($shape, 'a');
    /** @psalm-check-type-exact $_result = int */
}

// --- positive: optional key, no $default -> value|null ---

/** @param array{a?: int} $shape */
function test_get_optional_key_no_default(array $shape): void
{
    $_result = Arr::get($shape, 'a');
    /** @psalm-check-type-exact $_result = int|null */
}

// --- positive: optional key, with $default -> value|typeof($default) ---

/** @param array{a?: int} $shape */
function test_get_optional_key_with_default(array $shape): void
{
    $_result = Arr::get($shape, 'a', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback'|int */
}

// --- positive: absent key on a sealed shape, with $default ---

/** @param array{a: int} $shape */
function test_get_absent_key_with_default(array $shape): void
{
    $_result = Arr::get($shape, 'z', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback' */
}

// --- positive: absent key on a sealed shape, no $default -> null ---

/** @param array{a: int} $shape */
function test_get_absent_key_no_default(array $shape): void
{
    $_result = Arr::get($shape, 'z');
    /** @psalm-check-type-exact $_result = null */
}

// --- positive: nested dot path ---

/** @param array{a: array{b: int}} $shape */
function test_get_nested_dot_path(array $shape): void
{
    $_result = Arr::get($shape, 'a.b');
    /** @psalm-check-type-exact $_result = int */
}

// --- positive: literal dot-containing key wins over the segment walk ---

/**
 * Arr::get() checks the WHOLE key via exists() before ever splitting on '.',
 * so a literal `'a.b'` property must win over descending into `a` -> `b`.
 *
 * @param array{'a.b': int, a: array{b: string}} $shape
 */
function test_get_literal_dot_key_wins(array $shape): void
{
    $_result = Arr::get($shape, 'a.b');
    /** @psalm-check-type-exact $_result = int */
}

// --- positive: optional intermediate segment unions $default and keeps walking ---

/** @param array{a?: array{b: int}} $shape */
function test_get_optional_intermediate_segment(array $shape): void
{
    $_result = Arr::get($shape, 'a.b', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback'|int */
}

// --- negative: handler declines, Psalm's default `mixed` survives ---

/** @param array{a: int} $shape */
function test_get_dynamic_key(array $shape, string $key): void
{
    $_result = Arr::get($shape, $key);
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array<string, mixed> $map */
function test_get_generic_array(array $map): void
{
    $_result = Arr::get($map, 'a');
    /** @psalm-check-type-exact $_result = mixed */
}

function test_get_plain_array(array $array): void
{
    $_result = Arr::get($array, 'a');
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array{a: int, ...<string, bool>} $shape */
function test_get_unsealed_shape_absent_key(array $shape): void
{
    $_result = Arr::get($shape, 'z');
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param list<int> $list */
function test_get_list(array $list): void
{
    $_result = Arr::get($list, 'a');
    /** @psalm-check-type-exact $_result = mixed */
}

function test_get_arrayobject_receiver(\ArrayObject $receiver): void
{
    $_result = Arr::get($receiver, 'a');
    /** @psalm-check-type-exact $_result = mixed */
}

/** @param array{a: int} $shape */
function test_get_closure_default(array $shape): void
{
    $_result = Arr::get($shape, 'z', static fn (): string => 'fallback');
    /** @psalm-check-type-exact $_result = mixed */
}

/** Dot path where an intermediate segment is missing entirely. */
/** @param array{a: array{b: int}} $shape */
function test_get_dot_path_missing_segment(array $shape): void
{
    $_result = Arr::get($shape, 'x.y', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback' */
}

/** Dot path where an intermediate segment exists but isn't itself a describable shape. */
/** @param array{a: int} $shape */
function test_get_dot_path_non_shape_intermediate(array $shape): void
{
    $_result = Arr::get($shape, 'a.b');
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
