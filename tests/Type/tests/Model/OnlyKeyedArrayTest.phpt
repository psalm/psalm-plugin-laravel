--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Customer;
use App\Models\Vehicle;
use Carbon\CarbonInterface;

/**
 * Model::only() should narrow to a TKeyedArray when the keys argument resolves
 * to literal strings. Without the handler, it returns array<string, mixed>.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/931
 */

/**
 * Array argument with @property keys: narrowed to per-key types.
 *
 * Customer declares @property string $id and @property CarbonInterface|null $email_verified_at.
 *
 * @return array{id: string, email_verified_at: CarbonInterface|null}
 */
function test_array_arg_with_property_keys(Customer $customer): array
{
    /** @psalm-check-type-exact $slice = array{id: string, email_verified_at: \Carbon\CarbonInterface|null} */
    $slice = $customer->only(['id', 'email_verified_at']);

    return $slice;
}

/**
 * Varargs form: same shape, same types.
 *
 * @return array{id: string, email_verified_at: CarbonInterface|null}
 */
function test_varargs_form(Customer $customer): array
{
    /** @psalm-check-type-exact $slice = array{id: string, email_verified_at: \Carbon\CarbonInterface|null} */
    $slice = $customer->only('id', 'email_verified_at');

    return $slice;
}

/**
 * Single-string varargs.
 *
 * @return array{id: string}
 */
function test_single_string_arg(Customer $customer): array
{
    /** @psalm-check-type-exact $slice = array{id: string} */
    $slice = $customer->only('id');

    return $slice;
}

/**
 * Unknown key with no @property declaration: shape narrowed, value falls back to mixed.
 *
 * @return array{id: string, no_such_property: mixed}
 */
function test_unknown_key_falls_back_to_mixed(Customer $customer): array
{
    /** @psalm-check-type-exact $slice = array{id: string, no_such_property: mixed} */
    $slice = $customer->only(['id', 'no_such_property']);

    return $slice;
}

/**
 * Non-literal key: handler returns null, Laravel's signature applies.
 *
 * @return array<string, mixed>
 */
function test_non_literal_arg_falls_back(Customer $customer, string $key): array
{
    /** @psalm-check-type-exact $slice = array<string, mixed> */
    $slice = $customer->only([$key]);

    return $slice;
}

/**
 * Empty array: handler returns null, Laravel's signature applies.
 *
 * @return array<string, mixed>
 */
function test_empty_array_falls_back(Customer $customer): array
{
    /** @psalm-check-type-exact $slice = array<string, mixed> */
    $slice = $customer->only([]);

    return $slice;
}

/**
 * Unsealed shape (`list<'a'|'b'>` from a function return): handler falls back,
 * since the fallback_params admit additional keys not statically enumerable.
 *
 * @return list<'id'|'email_verified_at'>
 */
function helper_keys_as_list(): array
{
    return ['id', 'email_verified_at'];
}

/**
 * @return array<string, mixed>
 */
function test_unsealed_list_falls_back(Customer $customer): array
{
    /** @psalm-check-type-exact $slice = array<string, mixed> */
    $slice = $customer->only(helper_keys_as_list());

    return $slice;
}

/**
 * Mixed literal and non-literal entries in an array argument: handler falls back.
 *
 * @return array<string, mixed>
 */
function test_array_arg_mixed_literal_and_var_falls_back(Customer $customer, string $key): array
{
    /** @psalm-check-type-exact $slice = array<string, mixed> */
    $slice = $customer->only(['id', $key]);

    return $slice;
}

/**
 * Mixed literal and non-literal positional arguments: handler falls back.
 *
 * @return array<string, mixed>
 */
function test_varargs_mixed_literal_and_var_falls_back(Customer $customer, string $key): array
{
    /** @psalm-check-type-exact $slice = array<string, mixed> */
    $slice = $customer->only('id', $key);

    return $slice;
}

/**
 * Plain `array<int, string>` (no literal keys): handler falls back.
 *
 * @param array<int, string> $keys
 * @return array<string, mixed>
 */
function test_plain_typed_array_falls_back(Customer $customer, array $keys): array
{
    /** @psalm-check-type-exact $slice = array<string, mixed> */
    $slice = $customer->only($keys);

    return $slice;
}

/**
 * Multi-atomic Union of distinct shapes (`['id'] | ['email_verified_at']`): handler
 * falls back rather than flattening keys across both atomics, since the runtime
 * value matches only one of the two shapes.
 *
 * @return array<string, mixed>
 */
function test_union_of_distinct_shapes_falls_back(Customer $customer, bool $cond): array
{
    $keys = $cond ? ['id'] : ['email_verified_at'];
    /** @psalm-check-type-exact $slice = array<string, mixed> */
    $slice = $customer->only($keys);

    return $slice;
}

/**
 * Object-typed @property value passes through `only()` unchanged.
 *
 * Customer declares `@property Vehicle|null $primary_vehicle`.
 *
 * @return array{primary_vehicle: Vehicle|null}
 */
function test_object_property_value(Customer $customer): array
{
    /** @psalm-check-type-exact $slice = array{primary_vehicle: \App\Models\Vehicle|null} */
    $slice = $customer->only(['primary_vehicle']);

    return $slice;
}
?>
--EXPECTF--
