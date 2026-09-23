--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * `old()`'s `$default` accepts anything: the runtime chain ends in `Arr::get($input, $key, $default)`,
 * which neither constrains nor inspects it. Laravel's own `@param` is narrower than that contract,
 * and templates routinely pass an int, a bool or an enum as the fallback for an optional field.
 */

function test_old_default_int(): string|array|null
{
    return old('qty', 1);
}

function test_old_default_bool(): string|array|null
{
    return old('active', false);
}

function test_old_default_float(): string|array|null
{
    return old('rate', 1.5);
}

function test_old_default_object(): string|array|null
{
    return old('at', new \DateTimeImmutable());
}

/** The shapes Laravel's own docblock already allowed keep working. */
function test_old_default_model(): string|array|null
{
    return old('customer', new Customer());
}

function test_old_default_string(): string|array|null
{
    return old('name', 'anonymous');
}

function test_old_no_default(): string|array|null
{
    return old('name');
}

?>
--EXPECTF--
