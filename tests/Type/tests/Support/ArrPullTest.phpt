--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Arr::pull($array, $key, $default) is `static::get()` followed by `static::forget()`:
 *  - the return type goes through the same resolver as Arr::get() (ArrGetTest);
 *  - the by-reference $array loses the pulled top-level key.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2345
 */

// --- return type: positive ---

/** @param array{name: string, age: int} $data */
function test_pull_known_key(array $data): void
{
    $_name = Arr::pull($data, 'name');
    /** @psalm-check-type-exact $_name = string */
}

/** @param array{a?: int} $data */
function test_pull_optional_key_no_default(array $data): void
{
    $_value = Arr::pull($data, 'a');
    /** @psalm-check-type-exact $_value = int|null */
}

/** @param array{a?: int} $data */
function test_pull_optional_key_with_default(array $data): void
{
    $_value = Arr::pull($data, 'a', 'fallback');
    /** @psalm-check-type-exact $_value = 'fallback'|int */
}

/** @param array{a: int} $data */
function test_pull_absent_key_with_default(array $data): void
{
    $_value = Arr::pull($data, 'z', 'fallback');
    /** @psalm-check-type-exact $_value = 'fallback' */
}

/** @param array{user: array{name: string}} $data */
function test_pull_dot_key(array $data): void
{
    $_name = Arr::pull($data, 'user.name');
    /** @psalm-check-type-exact $_name = string */
}

/** @param array{'a.b': int, a: array{b: string}} $data */
function test_pull_literal_dot_key_wins_over_walk(array $data): void
{
    $_value = Arr::pull($data, 'a.b');
    /** @psalm-check-type-exact $_value = int */
}

// --- return type: handler declines, Psalm's `mixed` survives ---

/** @param array{a: int} $data */
function test_pull_closure_default_deferred(array $data): void
{
    $_value = Arr::pull($data, 'z', static fn (): string => 'fallback');
    /** @psalm-check-type-exact $_value = mixed */
}

/** @param array{a: int} $data */
function test_pull_dynamic_key(array $data, string $key): void
{
    $_value = Arr::pull($data, $key);
    /** @psalm-check-type-exact $_value = mixed */
}

/** @param array<string, int> $data */
function test_pull_generic_array(array $data): void
{
    $_value = Arr::pull($data, 'a');
    /** @psalm-check-type-exact $_value = mixed */
}

/** @param array{a: int, ...<string, bool>} $data */
function test_pull_unsealed_shape(array $data): void
{
    $_value = Arr::pull($data, 'a');
    /** @psalm-check-type-exact $_value = mixed */
}

// --- by-ref shape: the pulled top-level key is removed ---

/** @param array{name: string, age: int} $data */
function test_pull_removes_key_from_shape(array $data): void
{
    $_name = Arr::pull($data, 'name');
    /** @psalm-check-type-exact $data = array{age: int} */
}

/** @param array{a?: int, b: string} $data */
function test_pull_removes_optional_key(array $data): void
{
    $_value = Arr::pull($data, 'a');
    /** @psalm-check-type-exact $data = array{b: string} */
}

/** @param array{only: int} $data */
function test_pull_last_key_leaves_empty_array(array $data): void
{
    $_value = Arr::pull($data, 'only');
    /** @psalm-check-type-exact $data = array<never, never> */
}

/** @param array{name: string, age: int} $data */
function test_pull_two_keys_in_a_row(array $data): void
{
    $_name = Arr::pull($data, 'name');
    $_age = Arr::pull($data, 'age', 0);
    /** @psalm-check-type-exact $_age = int */
    /** @psalm-check-type-exact $data = array<never, never> */
}

/** @param array{a: int} $data */
function test_pull_absent_key_keeps_shape(array $data): void
{
    $_value = Arr::pull($data, 'z', 'fallback');
    /** @psalm-check-type-exact $data = array{a: int} */
}

/** @param array{'a.b': int, c: string} $data */
function test_pull_literal_dot_key_removed_whole(array $data): void
{
    $_value = Arr::pull($data, 'a.b');
    /** @psalm-check-type-exact $data = array{c: string} */
}

/** @param array{0: string, 1: int} $data */
function test_pull_first_list_key_is_not_a_list(array $data): void
{
    $_value = Arr::pull($data, '0');
    /** @psalm-check-type-exact $data = array{1: int} */
}

function test_pull_from_array_literal(): void
{
    $data = ['name' => 'x', 'age' => 1];
    $_name = Arr::pull($data, 'name');
    /** @psalm-check-type-exact $data = array{age: 1} */
}

/** Psalm merges a union of shapes into one shape whose differing keys are optional. */
/** @param array{a: int, b: string}|array{c: int} $data */
function test_pull_union_of_shapes_is_one_merged_shape(array $data): void
{
    $_value = Arr::pull($data, 'a');
    /** @psalm-check-type-exact $_value = int|null */
    /** @psalm-check-type-exact $data = array{b?: string, c?: int} */
}

// --- by-ref shape: handler declines, Psalm's own by-ref result (`array`) survives ---

/** @param array{user: array{name: string, id: int}} $data */
function test_pull_dot_key_leaves_psalms_array(array $data): void
{
    $_name = Arr::pull($data, 'user.name');
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

/** @param array{'a.b'?: int, a: array{b: string}} $data */
function test_pull_optional_literal_dot_key_declines(array $data): void
{
    $_value = Arr::pull($data, 'a.b');
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

/** @param array{a: int, ...<string, bool>} $data */
function test_pull_unsealed_shape_keeps_psalms_array(array $data): void
{
    $_value = Arr::pull($data, 'a');
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

/** @param array{a: int, b: string} $data */
function test_pull_dynamic_key_keeps_psalms_array(array $data, string $key): void
{
    $_value = Arr::pull($data, $key);
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

/** @param array{a: int, b: string} $data */
function test_pull_named_arguments_decline(array $data): void
{
    $_value = Arr::pull(key: 'a', array: $data);
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

// --- by-ref shape: later arguments that write the same variable invalidate the pre-call shape ---

/** @param array{a: int, b: string} $data */
function test_pull_nested_pull_in_default_declines(array $data): void
{
    $_value = Arr::pull($data, 'a', Arr::pull($data, 'b'));
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

/** @param array{a: int, b: string} $data */
function test_pull_assignment_in_default_declines(array $data): void
{
    $_value = Arr::pull($data, 'a', $data = ['a' => 1]);
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

/** @param array{a: int, b: string} $data */
function test_pull_with_side_effect_free_arguments_still_rewrites(array $data, int $fallback): void
{
    $_value = Arr::pull($data, 'a', $fallback);
    /** @psalm-check-type-exact $data = array{b: string} */
}

// --- by-ref shape: control-flow merges ---

/** @param array{a: int, b: string} $data */
function test_pull_in_one_branch_merges_to_optional_key(array $data, bool $cond): void
{
    if ($cond) {
        $_value = Arr::pull($data, 'a');
        /** @psalm-check-type-exact $data = array{b: string} */
    }

    /** @psalm-check-type-exact $data = array{a?: int, b: string} */
}

/** @param array{a: int, b: string} $data */
function test_pull_in_a_loop_merges_to_optional_key(array $data, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        $_value = Arr::pull($data, 'a');
    }

    /** @psalm-check-type-exact $data = array{a?: int, b: string} */
}

// --- by-ref shape: variables sharing storage keep Psalm's `array` ---

/** @param array{a: int, b: string} $data */
function test_pull_on_reference_source_declines(array $data): void
{
    $alias = &$data;
    $_value = Arr::pull($data, 'a');
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
    /** @psalm-check-type-exact $alias = array<array-key, mixed> */
}

/** @param array{a: int, b: string} $data */
function test_pull_on_reference_alias_declines(array $data): void
{
    $alias = &$data;
    $_value = Arr::pull($alias, 'a');
    /** @psalm-check-type-exact $alias = array<array-key, mixed> */
}

function test_pull_on_variable_captured_by_reference(): void
{
    $data = ['a' => 1, 'b' => 'x'];
    $pull = static function () use (&$data): void {
        Arr::pull($data, 'a');
    };
    $pull();
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}

/** @param array{string} $rest */
function test_pull_unpacked_arguments_decline(array $data, array $rest): void
{
    $_value = Arr::pull($data, ...$rest);
    /** @psalm-check-type-exact $data = array<array-key, mixed> */
}
?>
--EXPECTF--
