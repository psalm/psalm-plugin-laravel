--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Support\Arr;

/**
 * Arr::pluck() should infer value/key types from Eloquent model @property annotations,
 * the same way Builder::pluck()/Collection::pluck() already do.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1379
 */

// --- positive: no key arg -> list<TValue> ---

/** @param list<Customer> $customers */
function test_pluck_list_no_key(array $customers): void
{
    $_result = Arr::pluck($customers, 'id');
    /** @psalm-check-type-exact $_result = list<string> */
}

/** @param array<int, Vehicle> $vehicles */
function test_pluck_array_no_key(array $vehicles): void
{
    $_result = Arr::pluck($vehicles, 'make');
    /** @psalm-check-type-exact $_result = list<string> */
}

/** @param iterable<Vehicle> $vehicles */
function test_pluck_iterable_no_key(iterable $vehicles): void
{
    $_result = Arr::pluck($vehicles, 'make');
    /** @psalm-check-type-exact $_result = list<string> */
}

/** @param array{Customer, Customer} $customers */
function test_pluck_keyed_array_no_key(array $customers): void
{
    $_result = Arr::pluck($customers, 'id');
    /** @psalm-check-type-exact $_result = list<string> */
}

// --- positive: with key arg -> array<TKey, TValue> ---

/** @param list<Customer> $customers */
function test_pluck_with_key(array $customers): void
{
    $_result = Arr::pluck($customers, 'id', 'vehicles_count');
    /** @psalm-check-type-exact $_result = array<int<0, max>, string> */
}

/** @param list<Customer> $customers */
function test_pluck_with_nullable_value_and_key(array $customers): void
{
    $_result = Arr::pluck($customers, 'email_verified_at', 'id');
    /** @psalm-check-type-exact $_result = array<string, \Carbon\CarbonInterface|null> */
}

/**
 * Key column @property is not array-key compatible (CarbonInterface|null) — falls
 * back to array-key instead of producing an invalid TKey.
 *
 * @param list<Customer> $customers
 */
function test_pluck_with_non_array_key_compatible_key(array $customers): void
{
    $_result = Arr::pluck($customers, 'id', 'email_verified_at');
    /** @psalm-check-type-exact $_result = array<array-key, string> */
}

// --- negative: handler declines, Psalm's default array<array-key, mixed> survives ---

/** Dynamic column name: not a string literal, handler bails entirely. */
function test_pluck_dynamic_column(array $customers, string $column): void
{
    $_result = Arr::pluck($customers, $column);
    /** @psalm-check-type-exact $_result = array<array-key, mixed> */
}

/**
 * Mixed-model union element type: extractModelFromIterableValueType() declines a
 * union of more than one Model atomic.
 *
 * @param array<int, Customer|Vehicle> $mixed
 */
function test_pluck_mixed_model_union(array $mixed): void
{
    $_result = Arr::pluck($mixed, 'id');
    /** @psalm-check-type-exact $_result = array<array-key, mixed> */
}

/** Plain `array` with no value type param: element extraction declines. */
function test_pluck_plain_array(array $array): void
{
    $_result = Arr::pluck($array, 'id');
    /** @psalm-check-type-exact $_result = array<array-key, mixed> */
}

/** Non-model list: element type resolves but is not a Model, so decline. */
function test_pluck_non_model_list(): void
{
    $_result = Arr::pluck(['a', 'b'], 'id');
    /** @psalm-check-type-exact $_result = array<array-key, mixed> */
}

/**
 * Neither column is a known @property — neither axis narrows, handler defers
 * entirely to the stub's default.
 *
 * @param list<Customer> $customers
 */
function test_pluck_unknown_columns(array $customers): void
{
    $_result = Arr::pluck($customers, 'unknown_value', 'unknown_key');
    /** @psalm-check-type-exact $_result = array<array-key, mixed> */
}
?>
--EXPECTF--
