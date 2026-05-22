--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Companion to FindMixedNarrowingTest for #975. `Builder::findOr($mixed, fn)`
 * has the same conditional widening as `find()` plus an extra `TValue` template
 * from the callback. The handler collapses the mixed case to `TModel|TValue`,
 * with TValue resolved from the closure's return type.
 *
 * Laravel accepts the callback at arg position 1 (`findOr($id, $callback)`) or
 * arg position 2 (`findOr($id, $columns, $callback)`); the handler probes both.
 */
function test_find_or_mixed_with_callback_at_arg2(mixed $id): void
{
    $_r = Customer::query()->findOr($id, ['id'], fn() => 'fallback');
    /** @psalm-check-type-exact $_r = Customer|'fallback' */
}

function test_find_or_mixed_with_callback_at_arg1(mixed $id): void
{
    $_r = Customer::query()->findOr($id, fn() => 'fallback');
    /** @psalm-check-type-exact $_r = Customer|'fallback' */
}

function test_find_or_mixed_no_callback_falls_back_to_null(mixed $id): void
{
    // Default callback is null at runtime — the handler returns TModel|null
    // when no closure is found in the arg list.
    $_r = Customer::query()->findOr($id);
    /** @psalm-check-type-exact $_r = Customer|null */
}

?>
--EXPECTF--
