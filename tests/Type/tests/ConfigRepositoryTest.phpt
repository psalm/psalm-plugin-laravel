--FILE--
<?php declare(strict_types=1);

use Illuminate\Config\Repository;

// Valid: no default (null implied)
function test_array_no_default(Repository $config): void
{
    $_result = $config->array('foo');
    /** @psalm-check-type-exact $_result = array<array-key, mixed> */
}

// Valid: explicit null default
function test_array_null_default(Repository $config): array
{
    return $config->array('foo', null);
}

// Valid: array default
function test_array_array_default(Repository $config): array
{
    return $config->array('foo', ['bar' => 1]);
}

// Valid: Closure default returning array — Laravel calls it via Arr::get() → value()
function test_array_closure_array_default(Repository $config): array
{
    return $config->array('foo', fn () => ['bar' => 1]);
}

// Valid: Closure default returning null — same resolution path
function test_array_closure_null_default(Repository $config): array
{
    return $config->array('foo', fn () => null);
}

// Valid: collection, no default
function test_collection_no_default(Repository $config): void
{
    $_result = $config->collection('foo');
    /** @psalm-check-type-exact $_result = Illuminate\Support\Collection<array-key, mixed> */
}

// Valid: collection, null default
function test_collection_null_default(Repository $config): \Illuminate\Support\Collection
{
    return $config->collection('foo', null);
}

// Valid: collection, array default
function test_collection_array_default(Repository $config): \Illuminate\Support\Collection
{
    return $config->collection('foo', ['bar' => 1]);
}

// Valid: collection, Closure default returning array
function test_collection_closure_default(Repository $config): \Illuminate\Support\Collection
{
    return $config->collection('foo', fn () => ['bar' => 1]);
}

// Invalid: string default for array() — always throws at runtime when key is absent
function test_array_string_default_is_invalid(Repository $config): array
{
    return $config->array('foo', 'fallback');
}

// Invalid: int default for array() — always throws at runtime when key is absent
function test_array_int_default_is_invalid(Repository $config): array
{
    return $config->array('foo', 42);
}

// Invalid: string default for collection() — delegates to array(), same runtime throw
function test_collection_string_default_is_invalid(Repository $config): \Illuminate\Support\Collection
{
    return $config->collection('foo', 'fallback');
}

// Invalid: int default for collection() — delegates to array(), same runtime throw
function test_collection_int_default_is_invalid(Repository $config): \Illuminate\Support\Collection
{
    return $config->collection('foo', 42);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Config\Repository::array expects array<array-key, mixed>|impure-Closure():(array<array-key, mixed>|null)|null, but 'fallback' provided
InvalidArgument on line %d: Argument 2 of Illuminate\Config\Repository::array expects array<array-key, mixed>|impure-Closure():(array<array-key, mixed>|null)|null, but 42 provided
InvalidArgument on line %d: Argument 2 of Illuminate\Config\Repository::collection expects array<array-key, mixed>|impure-Closure():(array<array-key, mixed>|null)|null, but 'fallback' provided
InvalidArgument on line %d: Argument 2 of Illuminate\Config\Repository::collection expects array<array-key, mixed>|impure-Closure():(array<array-key, mixed>|null)|null, but 42 provided
