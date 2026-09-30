--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * `old()`'s `$default` accepts anything: the runtime chain ends in `Arr::get($input, $key, $default)`,
 * which neither constrains nor inspects it, and hands the fallback back when the key is absent, so
 * its type joins the return. A `Model` default is the one exception: the runtime reads the model's
 * attribute instead of returning the model.
 */

function test_old_default_int(): void
{
    $_ = old('qty', 1);
    /** @psalm-check-type-exact $_ = string|array<array-key, mixed>|1 */
}

function test_old_default_bool(): void
{
    $_ = old('active', false);
    /** @psalm-check-type-exact $_ = string|array<array-key, mixed>|false */
}

function test_old_default_object(): void
{
    $_ = old('at', new \DateTimeImmutable());
    /** @psalm-check-type-exact $_ = string|array<array-key, mixed>|\DateTimeImmutable */
}

function test_old_default_model(): void
{
    $_ = old('customer', new Customer());
    /** @psalm-check-type-exact $_ = string|array<array-key, mixed>|null */
}

/** Psalm leaves the conditional unresolved for a string default and joins both branches: imprecise, still sound. */
function test_old_default_string(): void
{
    $_ = old('name', 'anonymous');
    /** @psalm-check-type-exact $_ = string|array<array-key, mixed>|null */
}

function test_old_no_default(): void
{
    $_ = old('name');
    /** @psalm-check-type-exact $_ = string|array<array-key, mixed>|null */
}

?>
--EXPECTF--
